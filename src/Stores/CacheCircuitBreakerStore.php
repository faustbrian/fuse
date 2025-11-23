<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse\Stores;

use Cline\Fuse\Contracts\CircuitBreakerStore;
use Cline\Fuse\Enums\CircuitBreakerState;
use Cline\Fuse\ValueObjects\CircuitBreakerMetrics;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;

final readonly class CacheCircuitBreakerStore implements CircuitBreakerStore
{
    public function __construct(
        private Repository $cache,
        private string $prefix = 'circuit_breaker',
    ) {}

    public function getState(string $name, ?Model $context = null, ?Model $boundary = null): CircuitBreakerState
    {
        $state = $this->cache->get($this->stateKey($name, $context, $boundary));

        if ($state === null) {
            return CircuitBreakerState::CLOSED;
        }

        return CircuitBreakerState::from($state);
    }

    public function getMetrics(string $name, ?Model $context = null, ?Model $boundary = null): CircuitBreakerMetrics
    {
        $data = $this->cache->get($this->metricsKey($name, $context, $boundary));

        if ($data === null) {
            return CircuitBreakerMetrics::empty();
        }

        return new CircuitBreakerMetrics(
            consecutiveSuccesses: $data['consecutive_successes'],
            consecutiveFailures: $data['consecutive_failures'],
            totalSuccesses: $data['total_successes'],
            totalFailures: $data['total_failures'],
            lastSuccessTime: $data['last_success_time'],
            lastFailureTime: $data['last_failure_time'],
        );
    }

    public function recordSuccess(string $name, ?Model $context = null, ?Model $boundary = null): void
    {
        $metrics = $this->getMetricsArray($name, $context, $boundary);

        $metrics['consecutive_successes']++;
        $metrics['consecutive_failures'] = 0;
        $metrics['total_successes']++;
        $metrics['last_success_time'] = time();

        $this->cache->forever($this->metricsKey($name, $context, $boundary), $metrics);
    }

    public function recordFailure(string $name, ?Model $context = null, ?Model $boundary = null): void
    {
        $metrics = $this->getMetricsArray($name, $context, $boundary);

        $metrics['consecutive_failures']++;
        $metrics['consecutive_successes'] = 0;
        $metrics['total_failures']++;
        $metrics['last_failure_time'] = time();

        $this->cache->forever($this->metricsKey($name, $context, $boundary), $metrics);
    }

    public function transitionToOpen(string $name, ?Model $context = null, ?Model $boundary = null): void
    {
        $this->cache->forever($this->stateKey($name, $context, $boundary), CircuitBreakerState::OPEN->value);
    }

    public function transitionToClosed(string $name, ?Model $context = null, ?Model $boundary = null): void
    {
        $this->cache->forever($this->stateKey($name, $context, $boundary), CircuitBreakerState::CLOSED->value);
        $this->resetMetrics($name, $context, $boundary);
    }

    public function transitionToHalfOpen(string $name, ?Model $context = null, ?Model $boundary = null): void
    {
        $this->cache->forever($this->stateKey($name, $context, $boundary), CircuitBreakerState::HALF_OPEN->value);
    }

    public function reset(string $name, ?Model $context = null, ?Model $boundary = null): void
    {
        $this->cache->forget($this->stateKey($name, $context, $boundary));
        $this->cache->forget($this->metricsKey($name, $context, $boundary));
    }

    private function stateKey(string $name, ?Model $context = null, ?Model $boundary = null): string
    {
        $parts = [$this->prefix];

        if ($context !== null) {
            $parts[] = $context->getMorphClass();
            $parts[] = (string) $context->getKey();
        }

        if ($boundary !== null) {
            $parts[] = $boundary->getMorphClass();
            $parts[] = (string) $boundary->getKey();
        }

        $parts[] = $name;
        $parts[] = 'state';

        return implode(':', $parts);
    }

    private function metricsKey(string $name, ?Model $context = null, ?Model $boundary = null): string
    {
        $parts = [$this->prefix];

        if ($context !== null) {
            $parts[] = $context->getMorphClass();
            $parts[] = (string) $context->getKey();
        }

        if ($boundary !== null) {
            $parts[] = $boundary->getMorphClass();
            $parts[] = (string) $boundary->getKey();
        }

        $parts[] = $name;
        $parts[] = 'metrics';

        return implode(':', $parts);
    }

    private function getMetricsArray(string $name, ?Model $context = null, ?Model $boundary = null): array
    {
        $metrics = $this->cache->get($this->metricsKey($name, $context, $boundary));

        if ($metrics === null) {
            return [
                'consecutive_successes' => 0,
                'consecutive_failures' => 0,
                'total_successes' => 0,
                'total_failures' => 0,
                'last_success_time' => null,
                'last_failure_time' => null,
            ];
        }

        return $metrics;
    }

    private function resetMetrics(string $name, ?Model $context = null, ?Model $boundary = null): void
    {
        $this->cache->forever($this->metricsKey($name, $context, $boundary), [
            'consecutive_successes' => 0,
            'consecutive_failures' => 0,
            'total_successes' => 0,
            'total_failures' => 0,
            'last_success_time' => null,
            'last_failure_time' => null,
        ]);
    }
}
