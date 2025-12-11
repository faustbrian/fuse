<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse;

use Cline\Fuse\Contracts\CircuitBreakerStore;
use Cline\Fuse\Contracts\Strategy;
use Cline\Fuse\Enums\CircuitBreakerState;
use Cline\Fuse\Events\CircuitBreakerClosed;
use Cline\Fuse\Events\CircuitBreakerHalfOpened;
use Cline\Fuse\Events\CircuitBreakerOpened;
use Cline\Fuse\Events\CircuitBreakerRequestAttempted;
use Cline\Fuse\Events\CircuitBreakerRequestFailed;
use Cline\Fuse\Events\CircuitBreakerRequestSucceeded;
use Cline\Fuse\Exceptions\CircuitBreakerOpenException;
use Cline\Fuse\ValueObjects\CircuitBreakerConfiguration;
use Cline\Fuse\ValueObjects\CircuitBreakerMetrics;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Throwable;

use function config;
use function event;

/**
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class CircuitBreaker
{
    public function __construct(
        private CircuitBreakerConfiguration $configuration,
        private CircuitBreakerStore $store,
        private Strategy $strategy,
        private ?Model $context = null,
        private ?Model $boundary = null,
    ) {}

    public function call(Closure $callback): mixed
    {
        $state = $this->getState();

        $this->dispatchEvent(
            new CircuitBreakerRequestAttempted($this->configuration->name, $state)
        );

        if ($state->isOpen()) {
            if ($this->shouldAttemptReset()) {
                $this->transitionToHalfOpen();

                return $this->attemptRequest($callback);
            }

            $this->handleOpenCircuit();
        }

        return $this->attemptRequest($callback);
    }

    public function recordSuccess(): void
    {
        $this->store->recordSuccess($this->configuration->name, $this->context, $this->boundary);

        $state = $this->getState();

        $this->dispatchEvent(
            new CircuitBreakerRequestSucceeded($this->configuration->name, $state)
        );

        if (!$state->isHalfOpen() || !$this->shouldClose()) {
            return;
        }

        $this->transitionToClosed();
    }

    public function recordFailure(): void
    {
        $this->store->recordFailure($this->configuration->name, $this->context, $this->boundary);

        $state = $this->getState();

        $this->dispatchEvent(
            new CircuitBreakerRequestFailed($this->configuration->name, $state)
        );

        if (!$state->canAttemptRequest() || !$this->shouldOpen()) {
            return;
        }

        $this->transitionToOpen();
    }

    public function getState(): CircuitBreakerState
    {
        return $this->store->getState($this->configuration->name, $this->context, $this->boundary);
    }

    public function getMetrics(): CircuitBreakerMetrics
    {
        return $this->store->getMetrics($this->configuration->name, $this->context, $this->boundary);
    }

    public function reset(): void
    {
        $this->store->reset($this->configuration->name, $this->context, $this->boundary);
        $this->transitionToClosed();
    }

    private function attemptRequest(Closure $callback): mixed
    {
        try {
            $result = $callback();
            $this->recordSuccess();

            return $result;
        } catch (Throwable $throwable) {
            if ($this->shouldRecordException($throwable)) {
                $this->recordFailure();
            }

            throw $throwable;
        }
    }

    private function shouldOpen(): bool
    {
        $metrics = $this->getMetrics();

        return $this->strategy->shouldOpen($metrics, $this->configuration);
    }

    private function shouldClose(): bool
    {
        $metrics = $this->getMetrics();

        return $metrics->consecutiveSuccesses >= $this->configuration->successThreshold;
    }

    private function shouldAttemptReset(): bool
    {
        $metrics = $this->getMetrics();

        if ($metrics->lastFailureTime === null) {
            return true;
        }

        return (Date::now()->getTimestamp() - $metrics->lastFailureTime) >= $this->configuration->timeout;
    }

    private function transitionToOpen(): void
    {
        $this->store->transitionToOpen($this->configuration->name, $this->context, $this->boundary);
        $this->dispatchEvent(
            new CircuitBreakerOpened($this->configuration->name)
        );
    }

    private function transitionToClosed(): void
    {
        $this->store->transitionToClosed($this->configuration->name, $this->context, $this->boundary);
        $this->dispatchEvent(
            new CircuitBreakerClosed($this->configuration->name)
        );
    }

    private function transitionToHalfOpen(): void
    {
        $this->store->transitionToHalfOpen($this->configuration->name, $this->context, $this->boundary);
        $this->dispatchEvent(
            new CircuitBreakerHalfOpened($this->configuration->name)
        );
    }

    private function handleOpenCircuit(): never
    {
        $fallback = $this->getFallbackHandler();

        if ($fallback instanceof Closure) {
            throw new CircuitBreakerOpenException(
                name: $this->configuration->name,
                fallbackValue: $fallback($this->configuration->name),
            );
        }

        throw new CircuitBreakerOpenException(
            name: $this->configuration->name,
        );
    }

    private function getFallbackHandler(): ?Closure
    {
        if (!config('fuse.fallbacks.enabled', true)) {
            return null;
        }

        $handlers = config('fuse.fallbacks.handlers', []);

        if (isset($handlers[$this->configuration->name])) {
            return Closure::fromCallable($handlers[$this->configuration->name]);
        }

        $default = config('fuse.fallbacks.default');

        if ($default !== null) {
            return Closure::fromCallable($default);
        }

        return null;
    }

    private function shouldRecordException(Throwable $exception): bool
    {
        $ignore = config('fuse.exceptions.ignore', []);
        $record = config('fuse.exceptions.record', []);

        foreach ($ignore as $ignoredClass) {
            if ($exception instanceof $ignoredClass) {
                return false;
            }
        }

        if (empty($record)) {
            return true;
        }

        foreach ($record as $recordClass) {
            if ($exception instanceof $recordClass) {
                return true;
            }
        }

        return false;
    }

    private function dispatchEvent(object $event): void
    {
        if (!config('fuse.events.enabled', true)) {
            return;
        }

        event($event);
    }
}
