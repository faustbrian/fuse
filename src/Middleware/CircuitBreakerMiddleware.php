<?php

namespace Cline\Fuse\Middleware;

use Cline\Fuse\CircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Queue middleware that routes jobs through a service-specific circuit breaker.
 *
 * The middleware is designed for jobs that talk to a named downstream system.
 * It pauses work while the service is marked open, coordinates half-open probe
 * attempts, and feeds success/failure outcomes back into the shared breaker.
 */
class CircuitBreakerMiddleware
{
    private readonly int $releaseDelay;

    /**
     * Build middleware for one service and optional runtime overrides.
     *
     * @param  string  $service  Configured service key under `fuse.services`
     * @param  int|null  $release  Optional delay before retrying blocked jobs
     * @param  int|null  $window  Optional override for the breaker's accounting window
     */
    public function __construct(
        private readonly string $service,
        private readonly ?int $release = null,
        private readonly ?int $window = null,
    ) {
        $config = config("fuse.services.{$this->service}", []);

        $this->releaseDelay = $this->release
            ?? ($config['release'] ?? null)
            ?? config('fuse.default_release')
            ?? 10;
    }

    /**
     * Execute the job through the breaker lifecycle.
     *
     * Closed circuits allow the job immediately. Open circuits release the job
     * back to the queue. Half-open circuits ask the recovery strategy whether
     * this job may act as the current probe and update breaker state based on
     * the resulting success or failure.
     *
     * @param  mixed  $job  Queue job instance supporting `release()`
     * @param  callable  $next  Next middleware / job handler callback
     */
    public function handle(mixed $job, callable $next): mixed
    {
        if (! $this->isEnabled()) {
            return $next($job);
        }

        $breaker = new CircuitBreaker($this->service, $this->window);

        if ($breaker->isOpen()) {
            return $job->release($this->releaseDelay);
        }

        if ($breaker->isHalfOpen()) {
            if (! $breaker->recoveryStrategy()->allowsAttempt($breaker)) {
                return $job->release($this->releaseDelay);
            }

            try {
                $result = $next($job);
                $breaker->recordSuccess();

                return $result;
            } catch (Throwable $e) {
                $before = $breaker->getState();
                $breaker->recordFailure($e);

                if ($breaker->getState() === $before) {
                    $breaker->recoveryStrategy()->recordFailure($breaker);
                }

                throw $e;
            }
        }

        try {
            $result = $next($job);
            $breaker->recordSuccess();

            return $result;
        } catch (Throwable $e) {
            $breaker->recordFailure($e);
            throw $e;
        }
    }

    /**
     * Determine whether Fuse protection is enabled for the application.
     *
     * A cached toggle wins over config so operators can disable breaker
     * enforcement at runtime without redeploying the host application.
     */
    private function isEnabled(): bool
    {
        $prefix = config('fuse.cache.prefix', 'fuse');
        $cacheValue = Cache::get("{$prefix}:enabled");

        if ($cacheValue !== null) {
            return (bool) $cacheValue;
        }

        return config('fuse.enabled', true);
    }
}
