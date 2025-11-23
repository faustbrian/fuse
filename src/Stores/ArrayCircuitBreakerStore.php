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
use Illuminate\Database\Eloquent\Model;

final class ArrayCircuitBreakerStore implements CircuitBreakerStore
{
    private array $states = [];

    private array $metrics = [];

    public function getState(string $name, ?Model $context = null, ?Model $boundary = null): CircuitBreakerState
    {
        $key = $this->buildKey($name, $context, $boundary);

        return $this->states[$key] ?? CircuitBreakerState::CLOSED;
    }

    public function getMetrics(string $name, ?Model $context = null, ?Model $boundary = null): CircuitBreakerMetrics
    {
        $key = $this->buildKey($name, $context, $boundary);

        if (! isset($this->metrics[$key])) {
            return CircuitBreakerMetrics::empty();
        }

        $data = $this->metrics[$key];

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
        $key = $this->buildKey($name, $context, $boundary);
        $this->ensureMetricsExist($key);

        $this->metrics[$key]['consecutive_successes']++;
        $this->metrics[$key]['consecutive_failures'] = 0;
        $this->metrics[$key]['total_successes']++;
        $this->metrics[$key]['last_success_time'] = time();
    }

    public function recordFailure(string $name, ?Model $context = null, ?Model $boundary = null): void
    {
        $key = $this->buildKey($name, $context, $boundary);
        $this->ensureMetricsExist($key);

        $this->metrics[$key]['consecutive_failures']++;
        $this->metrics[$key]['consecutive_successes'] = 0;
        $this->metrics[$key]['total_failures']++;
        $this->metrics[$key]['last_failure_time'] = time();
    }

    public function transitionToOpen(string $name, ?Model $context = null, ?Model $boundary = null): void
    {
        $key = $this->buildKey($name, $context, $boundary);
        $this->states[$key] = CircuitBreakerState::OPEN;
    }

    public function transitionToClosed(string $name, ?Model $context = null, ?Model $boundary = null): void
    {
        $key = $this->buildKey($name, $context, $boundary);
        $this->states[$key] = CircuitBreakerState::CLOSED;
        $this->resetMetrics($key);
    }

    public function transitionToHalfOpen(string $name, ?Model $context = null, ?Model $boundary = null): void
    {
        $key = $this->buildKey($name, $context, $boundary);
        $this->states[$key] = CircuitBreakerState::HALF_OPEN;
    }

    public function reset(string $name, ?Model $context = null, ?Model $boundary = null): void
    {
        $key = $this->buildKey($name, $context, $boundary);
        unset($this->states[$key]);
        unset($this->metrics[$key]);
    }

    private function buildKey(string $name, ?Model $context = null, ?Model $boundary = null): string
    {
        $parts = [];

        if ($context !== null) {
            $parts[] = $context->getMorphClass();
            $parts[] = (string) $context->getKey();
        }

        if ($boundary !== null) {
            $parts[] = $boundary->getMorphClass();
            $parts[] = (string) $boundary->getKey();
        }

        $parts[] = $name;

        return implode(':', $parts);
    }

    private function ensureMetricsExist(string $key): void
    {
        if (! isset($this->metrics[$key])) {
            $this->metrics[$key] = [
                'consecutive_successes' => 0,
                'consecutive_failures' => 0,
                'total_successes' => 0,
                'total_failures' => 0,
                'last_success_time' => null,
                'last_failure_time' => null,
            ];
        }
    }

    private function resetMetrics(string $key): void
    {
        $this->metrics[$key] = [
            'consecutive_successes' => 0,
            'consecutive_failures' => 0,
            'total_successes' => 0,
            'total_failures' => 0,
            'last_success_time' => null,
            'last_failure_time' => null,
        ];
    }
}
