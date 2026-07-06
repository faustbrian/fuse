<?php

namespace Cline\Fuse;

use Cline\Fuse\Classifiers\DefaultFailureClassifier;
use Cline\Fuse\Contracts\FailureClassifier;
use Cline\Fuse\Contracts\RecoveryStrategy;
use Cline\Fuse\Enums\CircuitState;
use Cline\Fuse\Events\CircuitBreakerClosed;
use Cline\Fuse\Events\CircuitBreakerHalfOpen;
use Cline\Fuse\Events\CircuitBreakerOpened;
use Cline\Fuse\Strategies\SingleProbe;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * Cache-backed circuit breaker for one configured downstream service.
 *
 * The breaker owns the runtime state machine for a single service name from
 * `config/fuse.php`. It keeps request counters, failure counters, open/closed
 * state, and half-open recovery coordination in cache so multiple queue
 * workers can share the same view of service health.
 *
 * Failure tracking is windowed, while state transitions are protected with a
 * cache lock to avoid duplicate event dispatch and conflicting writes when
 * several workers observe the same outage at once.
 */
class CircuitBreaker
{
    private readonly int $failureThreshold;

    private readonly int $timeout;

    private readonly int $minRequests;

    private readonly int $windowSeconds;

    private readonly int $probeLeaseSeconds;

    private readonly string $cachePrefix;

    private readonly FailureClassifier $failureClassifier;

    private readonly RecoveryStrategy $recoveryStrategy;

    /**
     * Build a breaker for the named service configuration.
     *
     * Per-service values override package defaults. The optional `$window`
     * argument lets callers narrow or widen the rolling accounting window for
     * a single execution path without mutating the persisted package config.
     *
     * @param  string  $serviceName  Configured service key under `fuse.services`
     * @param  int|null  $window  Optional override for the failure-tracking window
     */
    public function __construct(private readonly string $serviceName, ?int $window = null)
    {
        $config = config("fuse.services.{$serviceName}", []);

        $this->failureThreshold = ThresholdCalculator::for($serviceName);

        $this->timeout = $config['timeout']
            ?? config('fuse.default_timeout', 60);

        $this->minRequests = $config['min_requests']
            ?? config('fuse.default_min_requests', 10);

        $this->windowSeconds = max(1, (int) (
            $window
            ?? $config['window']
            ?? config('fuse.default_window', 60)
        ));

        $this->probeLeaseSeconds = max(1, (int) (
            $config['probe_lease']
            ?? config('fuse.default_probe_lease', 30)
        ));

        $this->cachePrefix = config('fuse.cache.prefix', 'fuse');

        $this->failureClassifier = $this->resolveFailureClassifier($config);

        $this->recoveryStrategy = $this->resolveRecoveryStrategy($config);
    }

    /**
     * Get the recovery strategy coordinating half-open traffic.
     *
     * Middleware uses this to decide whether the current probe job may pass
     * through while the breaker is testing if the dependency has recovered.
     */
    public function recoveryStrategy(): RecoveryStrategy
    {
        return $this->recoveryStrategy;
    }

    /**
     * Get the lease duration used by the default half-open probe strategy.
     *
     * This defines how long a worker owns the recovery probe before another
     * worker may retry if the owner crashes or stalls.
     */
    public function probeLeaseSeconds(): int
    {
        return $this->probeLeaseSeconds;
    }

    /**
     * Determine whether the service is currently considered unavailable.
     *
     * When the breaker is open and its timeout has elapsed, this method is also
     * responsible for advancing the breaker into half-open so recovery probes
     * can begin on the next attempt.
     */
    public function isOpen(): bool
    {
        if ($this->getState() !== CircuitState::Open) {
            return false;
        }

        $openedAt = Cache::get($this->key('opened_at'));

        if ($openedAt && (time() - $openedAt) >= $this->timeout) {
            $this->transitionTo(CircuitState::HalfOpen);

            return false;
        }

        return true;
    }

    /**
     * Determine whether the breaker is in its recovery-probe phase.
     */
    public function isHalfOpen(): bool
    {
        return $this->getState() === CircuitState::HalfOpen;
    }

    /**
     * Determine whether requests should flow normally.
     */
    public function isClosed(): bool
    {
        return $this->getState() === CircuitState::Closed;
    }

    /**
     * Record a successful attempt against the service.
     *
     * Successes always increment the request counter for the active window.
     * When the breaker is half-open, the recovery strategy decides whether this
     * success is enough evidence to fully close the circuit again.
     */
    public function recordSuccess(): void
    {
        $this->incrementAttempts();

        if ($this->getState() === CircuitState::HalfOpen) {
            if ($this->recoveryStrategy->recordSuccess($this)) {
                $this->transitionTo(CircuitState::Closed);
            }
        }
    }

    /**
     * Record a failed attempt and update breaker state if needed.
     *
     * Non-counted failures still contribute to request volume so the failure
     * percentage remains honest. Counted failures may reopen a half-open
     * circuit immediately, or trip a closed circuit once the configured
     * request floor and threshold are both satisfied.
     *
     * @param  Throwable|null  $exception  Failure being classified, if available
     */
    public function recordFailure(?Throwable $exception = null): void
    {
        if ($exception !== null && ! $this->failureClassifier->shouldCount($exception)) {
            $this->incrementAttempts();

            return;
        }

        if ($this->getState() === CircuitState::HalfOpen) {
            $this->recoveryStrategy->recordFailure($this);
            $this->transitionTo(CircuitState::Open, 100, 1, 1);

            return;
        }

        $window = $this->getCurrentWindow();
        $attemptsKey = $this->key("attempts:{$window}");
        $failuresKey = $this->key("failures:{$window}");

        $attempts = (int) Cache::increment($attemptsKey);
        $failures = (int) Cache::increment($failuresKey);

        Cache::put($attemptsKey, $attempts, $this->windowTtl());
        Cache::put($failuresKey, $failures, $this->windowTtl());

        $failureRate = $attempts > 0 ? ($failures / $attempts) * 100 : 0;

        if ($attempts >= $this->minRequests && $failureRate >= $this->failureThreshold) {
            $this->transitionTo(CircuitState::Open, $failureRate, $attempts, $failures);
        }
    }

    /**
     * Resolve the classifier used to decide whether an exception is breaker-worthy.
     *
     * Services may provide a classifier class in configuration to exclude
     * known-safe failures, such as throttling or authentication responses that
     * should not trip infrastructure health protection.
     *
     * @param  array<string, mixed>  $config
     */
    private function resolveFailureClassifier(array $config): FailureClassifier
    {
        if (! isset($config['failure_classifier'])) {
            return new DefaultFailureClassifier;
        }

        $classifier = app($config['failure_classifier']);

        if (! $classifier instanceof FailureClassifier) {
            throw new InvalidArgumentException(
                "Class [{$config['failure_classifier']}] must implement ".FailureClassifier::class
            );
        }

        return $classifier;
    }

    /**
     * Resolve the strategy that controls half-open recovery behavior.
     *
     * Recovery strategies decide how aggressively Fuse should probe a service
     * after the open timeout expires. The default `SingleProbe` strategy allows
     * one in-flight probe at a time across all workers.
     *
     * @param  array<string, mixed>  $config
     */
    private function resolveRecoveryStrategy(array $config): RecoveryStrategy
    {
        if (! isset($config['recovery_strategy'])) {
            return new SingleProbe;
        }

        $strategy = app($config['recovery_strategy']);

        if (! $strategy instanceof RecoveryStrategy) {
            throw new InvalidArgumentException(
                "Class [{$config['recovery_strategy']}] must implement ".RecoveryStrategy::class
            );
        }

        return $strategy;
    }

    /**
     * Read the persisted breaker state from cache.
     *
     * Missing state is treated as closed so a new service starts healthy until
     * observed failures prove otherwise.
     */
    public function getState(): CircuitState
    {
        $state = Cache::get($this->key('state'), CircuitState::Closed->value);

        return CircuitState::from($state);
    }

    /**
     * Get the current operational snapshot for this service.
     *
     * The payload is designed for artisan status output and external consumers
     * that need both the persisted state and the live configuration values that
     * govern timeout, request minimums, and failure thresholds.
     *
     * @return array{state: string, attempts: int, failures: int, failure_rate: float, opened_at: ?int, recovery_at: ?int, timeout: int, threshold: int, min_requests: int, window: int}
     */
    public function getStats(): array
    {
        $window = $this->getCurrentWindow();
        $attempts = (int) Cache::get($this->key("attempts:{$window}"), 0);
        $failures = (int) Cache::get($this->key("failures:{$window}"), 0);
        $openedAt = Cache::get($this->key('opened_at'));
        $state = $this->getState();

        return [
            'state' => $state->value,
            'attempts' => $attempts,
            'failures' => $failures,
            'failure_rate' => $attempts > 0 ? round(($failures / $attempts) * 100, 1) : 0,
            'opened_at' => $openedAt,
            'recovery_at' => $openedAt ? (int) $openedAt + $this->timeout : null,
            'timeout' => $this->timeout,
            'threshold' => $this->failureThreshold,
            'min_requests' => $this->minRequests,
            'window' => $this->windowSeconds,
        ];
    }

    /**
     * Clear all cached state and counters for the current service.
     *
     * This removes the breaker state, the active window counters, and any
     * probe/transition locks so the next attempt starts from a clean slate.
     */
    public function reset(): void
    {
        Cache::forget($this->key('state'));
        Cache::forget($this->key('opened_at'));
        $this->clearCurrentWindowCounters();
        $this->releaseLocks();
    }

    /**
     * Force the breaker open without waiting for threshold evaluation.
     *
     * This is intended for operational intervention from artisan commands.
     * The open timestamp is written immediately so timeout-based half-open
     * recovery still behaves the same as an automatically tripped circuit.
     */
    public function forceOpen(): void
    {
        Cache::put($this->key('state'), CircuitState::Open->value);
        Cache::put($this->key('opened_at'), time());

        event(new CircuitBreakerOpened($this->serviceName));
    }

    /**
     * Force the breaker closed without waiting for recovery probes.
     *
     * This clears the open timestamp and dispatches the same closure event used
     * by normal recovery so listeners do not need a separate manual path.
     */
    public function forceClose(bool $preserveHistory = false): void
    {
        Cache::put($this->key('state'), CircuitState::Closed->value);
        Cache::forget($this->key('opened_at'));

        if (! $preserveHistory) {
            $this->clearCurrentWindowCounters();
        }

        Cache::lock($this->key('probe'))->forceRelease();

        event(new CircuitBreakerClosed($this->serviceName));
    }

    /**
     * Persist a new breaker state and emit the matching lifecycle event.
     *
     * Transition writes are guarded by a short-lived cache lock so competing
     * workers cannot double-dispatch events or overwrite each other while the
     * breaker is moving between closed, open, and half-open.
     */
    private function transitionTo(
        CircuitState $newState,
        float $failureRate = 0,
        int $attempts = 0,
        int $failures = 0
    ): void {
        $lock = Cache::lock($this->key('transition'), 5);

        $acquired = $lock->get(function () use ($newState) {
            if ($this->getState() === $newState) {
                return false;
            }

            Cache::put($this->key('state'), $newState->value);

            if ($newState === CircuitState::Open) {
                Cache::put($this->key('opened_at'), time());
            }

            if ($newState === CircuitState::Closed) {
                Cache::forget($this->key('opened_at'));
                $this->clearCurrentWindowCounters();
                Cache::lock($this->key('probe'))->forceRelease();
            }

            return true;
        });

        if ($acquired) {
            match ($newState) {
                CircuitState::Open => event(new CircuitBreakerOpened(
                    $this->serviceName,
                    $failureRate,
                    $attempts,
                    $failures
                )),
                CircuitState::HalfOpen => event(new CircuitBreakerHalfOpen($this->serviceName)),
                CircuitState::Closed => event(new CircuitBreakerClosed($this->serviceName)),
            };
        }
    }

    /**
     * Increment the request volume for the active tracking window.
     *
     * Fuse counts both successful attempts and ignored failures as activity so
     * the failure percentage is measured against real traffic volume.
     */
    private function incrementAttempts(): void
    {
        $window = $this->getCurrentWindow();
        $key = $this->key("attempts:{$window}");

        $attempts = (int) Cache::increment($key);
        Cache::put($key, $attempts, $this->windowTtl());
    }

    /**
     * Forget the active window's request and failure counters.
     *
     * Closing the breaker should give the dependency a fresh accounting window
     * so stale failure rates do not immediately reopen it.
     */
    private function clearCurrentWindowCounters(): void
    {
        $window = $this->getCurrentWindow();

        Cache::forget($this->key("attempts:{$window}"));
        Cache::forget($this->key("failures:{$window}"));
    }

    /**
     * Release any coordination locks associated with the breaker.
     */
    private function releaseLocks(): void
    {
        Cache::lock($this->key('probe'))->forceRelease();
        Cache::lock($this->key('transition'))->forceRelease();
    }

    /**
     * Get the cache lifetime used for request and failure counters.
     *
     * Counters live longer than a single accounting window so late readers and
     * near-boundary writes do not immediately lose the previous bucket.
     */
    private function windowTtl(): int
    {
        return $this->windowSeconds * 2;
    }

    /**
     * Resolve the current tumbling window identifier.
     *
     * The timestamp is divided by the configured window length so all workers
     * addressing the same second range increment the same cache keys.
     */
    private function getCurrentWindow(): string
    {
        return (string) intdiv(now()->getTimestamp(), $this->windowSeconds);
    }

    /**
     * Build a cache key for this service-scoped breaker state.
     *
     * All Fuse runtime data is namespaced by the configured cache prefix and
     * the logical service name so multiple applications can share a cache store
     * without colliding.
     */
    public function key(string $suffix): string
    {
        return "{$this->cachePrefix}:{$this->serviceName}:{$suffix}";
    }
}
