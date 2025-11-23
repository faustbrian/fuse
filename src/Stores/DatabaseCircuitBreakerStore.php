<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse\Stores;

use Cline\Fuse\Contracts\CircuitBreakerStore;
use Cline\Fuse\Database\CircuitBreaker;
use Cline\Fuse\Database\CircuitBreakerEvent;
use Cline\Fuse\Database\ModelRegistry;
use Cline\Fuse\Enums\CircuitBreakerState;
use Cline\Fuse\ValueObjects\CircuitBreakerMetrics;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

use function config;
use function now;

/**
 * Database-backed circuit breaker store implementation.
 *
 * Persists circuit breaker state and metrics to the database, allowing state
 * to be shared across application instances and maintained across deployments.
 * Provides full audit trail through event logging and proper transaction handling
 * for state consistency.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final readonly class DatabaseCircuitBreakerStore implements CircuitBreakerStore
{
    /**
     * Create a new database circuit breaker store instance.
     *
     * @param null|string    $connection     Optional database connection name
     * @param ModelRegistry  $modelRegistry  Registry for polymorphic key mappings
     */
    public function __construct(
        private ?string $connection = null,
        private ?ModelRegistry $modelRegistry = null,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getState(string $name, ?Model $context = null, ?Model $boundary = null): CircuitBreakerState
    {
        $circuitBreaker = $this->findOrCreate($name, $context, $boundary);

        return $circuitBreaker->state;
    }

    /**
     * {@inheritDoc}
     */
    public function getMetrics(string $name, ?Model $context = null, ?Model $boundary = null): CircuitBreakerMetrics
    {
        $circuitBreaker = $this->findOrCreate($name, $context, $boundary);

        return new CircuitBreakerMetrics(
            consecutiveSuccesses: $circuitBreaker->consecutive_successes,
            consecutiveFailures: $circuitBreaker->consecutive_failures,
            totalSuccesses: $circuitBreaker->total_successes,
            totalFailures: $circuitBreaker->total_failures,
            lastSuccessTime: $circuitBreaker->last_success_at?->timestamp,
            lastFailureTime: $circuitBreaker->last_failure_at?->timestamp,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function recordSuccess(string $name, ?Model $context = null, ?Model $boundary = null): void
    {
        DB::connection($this->connection)->transaction(function () use ($name, $context): void {
            $circuitBreaker = $this->findOrCreate($name, $context, $boundary);

            $circuitBreaker->update([
                'consecutive_successes' => $circuitBreaker->consecutive_successes + 1,
                'consecutive_failures' => 0,
                'total_successes' => $circuitBreaker->total_successes + 1,
                'last_success_at' => now(),
            ]);

            $this->recordEvent($circuitBreaker, 'success', [
                'consecutive_successes' => $circuitBreaker->consecutive_successes + 1,
                'total_successes' => $circuitBreaker->total_successes + 1,
            ]);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function recordFailure(string $name, ?Model $context = null, ?Model $boundary = null): void
    {
        DB::connection($this->connection)->transaction(function () use ($name, $context): void {
            $circuitBreaker = $this->findOrCreate($name, $context, $boundary);

            $circuitBreaker->update([
                'consecutive_failures' => $circuitBreaker->consecutive_failures + 1,
                'consecutive_successes' => 0,
                'total_failures' => $circuitBreaker->total_failures + 1,
                'last_failure_at' => now(),
            ]);

            $this->recordEvent($circuitBreaker, 'failure', [
                'consecutive_failures' => $circuitBreaker->consecutive_failures + 1,
                'total_failures' => $circuitBreaker->total_failures + 1,
            ]);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function transitionToOpen(string $name, ?Model $context = null, ?Model $boundary = null): void
    {
        DB::connection($this->connection)->transaction(function () use ($name, $context): void {
            $circuitBreaker = $this->findOrCreate($name, $context, $boundary);

            $circuitBreaker->update([
                'state' => CircuitBreakerState::OPEN,
                'opened_at' => now(),
                'closed_at' => null,
            ]);

            $this->recordEvent($circuitBreaker, 'opened', [
                'previous_state' => CircuitBreakerState::CLOSED->value,
                'new_state' => CircuitBreakerState::OPEN->value,
                'consecutive_failures' => $circuitBreaker->consecutive_failures,
            ]);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function transitionToClosed(string $name, ?Model $context = null, ?Model $boundary = null): void
    {
        DB::connection($this->connection)->transaction(function () use ($name, $context): void {
            $circuitBreaker = $this->findOrCreate($name, $context, $boundary);

            $previousState = $circuitBreaker->state;

            $circuitBreaker->update([
                'state' => CircuitBreakerState::CLOSED,
                'closed_at' => now(),
                'opened_at' => null,
                'consecutive_failures' => 0,
            ]);

            $this->recordEvent($circuitBreaker, 'closed', [
                'previous_state' => $previousState->value,
                'new_state' => CircuitBreakerState::CLOSED->value,
                'consecutive_successes' => $circuitBreaker->consecutive_successes,
            ]);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function transitionToHalfOpen(string $name, ?Model $context = null, ?Model $boundary = null): void
    {
        DB::connection($this->connection)->transaction(function () use ($name, $context): void {
            $circuitBreaker = $this->findOrCreate($name, $context, $boundary);

            $circuitBreaker->update([
                'state' => CircuitBreakerState::HALF_OPEN,
            ]);

            $this->recordEvent($circuitBreaker, 'half_opened', [
                'previous_state' => CircuitBreakerState::OPEN->value,
                'new_state' => CircuitBreakerState::HALF_OPEN->value,
            ]);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function reset(string $name, ?Model $context = null, ?Model $boundary = null): void
    {
        DB::connection($this->connection)->transaction(function () use ($name, $context): void {
            $circuitBreaker = $this->findOrCreate($name, $context, $boundary);

            $previousState = $circuitBreaker->state;

            $circuitBreaker->update([
                'state' => CircuitBreakerState::CLOSED,
                'consecutive_successes' => 0,
                'consecutive_failures' => 0,
                'total_successes' => 0,
                'total_failures' => 0,
                'last_success_at' => null,
                'last_failure_at' => null,
                'opened_at' => null,
                'closed_at' => now(),
            ]);

            $this->recordEvent($circuitBreaker, 'reset', [
                'previous_state' => $previousState->value,
                'new_state' => CircuitBreakerState::CLOSED->value,
            ]);
        });
    }

    /**
     * Find an existing circuit breaker or create a new one with default state.
     *
     * @param  string          $name     The circuit breaker name
     * @param  null|Model      $context  The polymorphic context or null for global
     * @param  null|Model      $boundary The polymorphic boundary or null for no boundary
     * @return CircuitBreaker  The found or created circuit breaker
     */
    private function findOrCreate(string $name, ?Model $context = null, ?Model $boundary = null): CircuitBreaker
    {
        /** @var class-string<CircuitBreaker> $modelClass */
        $modelClass = config('fuse.models.circuit_breaker', CircuitBreaker::class);

        $query = $modelClass::query()->where('name', $name);

        // Apply context filtering
        if ($context === null) {
            $query->whereNull('context_type')->whereNull('context_id');
        } else {
            $query->where('context_type', $context->getMorphClass())
                ->where('context_id', $context->getKey());
        }

        // Apply boundary filtering
        if ($boundary === null) {
            $query->whereNull('boundary_type')->whereNull('boundary_id');
        } else {
            $query->where('boundary_type', $boundary->getMorphClass())
                ->where('boundary_id', $boundary->getKey());
        }

        /** @var CircuitBreaker $circuitBreaker */
        $circuitBreaker = $query->firstOr(function () use ($modelClass, $name, $context, $boundary) {
            $attributes = [
                'name' => $name,
                'state' => CircuitBreakerState::CLOSED,
                'consecutive_successes' => 0,
                'consecutive_failures' => 0,
                'total_successes' => 0,
                'total_failures' => 0,
                'context_type' => null,
                'context_id' => null,
                'boundary_type' => null,
                'boundary_id' => null,
            ];

            if ($context !== null) {
                $attributes['context_type'] = $context->getMorphClass();
                $attributes['context_id'] = $context->getKey();
            }

            if ($boundary !== null) {
                $attributes['boundary_type'] = $boundary->getMorphClass();
                $attributes['boundary_id'] = $boundary->getKey();
            }

            return $modelClass::query()->create($attributes);
        });

        return $circuitBreaker;
    }

    /**
     * Record an event in the audit trail.
     *
     * @param  CircuitBreaker      $circuitBreaker The circuit breaker instance
     * @param  string              $eventType      The event type (success, failure, opened, etc.)
     * @param  array<string, mixed> $metadata       Additional event metadata
     */
    private function recordEvent(CircuitBreaker $circuitBreaker, string $eventType, array $metadata = []): void
    {
        /** @var class-string<CircuitBreakerEvent> $eventClass */
        $eventClass = config('fuse.models.circuit_breaker_event', CircuitBreakerEvent::class);

        $eventClass::query()->create([
            'circuit_breaker_id' => $circuitBreaker->getKey(),
            'event_type' => $eventType,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
