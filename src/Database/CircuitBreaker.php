<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse\Database;

use Cline\Fuse\Database\Concerns\HasFusePrimaryKey;
use Cline\Fuse\Enums\CircuitBreakerState;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Circuit breaker model for database persistence.
 *
 * Represents a circuit breaker's state, metrics, and lifecycle in the database.
 * Tracks consecutive and total successes/failures along with state transitions
 * and timestamps for monitoring and recovery behavior.
 *
 * @property int|string                        $id                     Primary key (int, ULID, or UUID based on config)
 * @property null|string                       $context_type           Polymorphic type of the owning context
 * @property null|string                       $context_id             Polymorphic ID of the owning context
 * @property null|string                       $boundary_type          Polymorphic type of the boundary scope
 * @property null|string                       $boundary_id            Polymorphic ID of the boundary scope
 * @property string                            $name                   Circuit breaker name
 * @property CircuitBreakerState               $state                  Current state (closed, open, half_open)
 * @property int                               $consecutive_successes  Consecutive successful requests
 * @property int                               $consecutive_failures   Consecutive failed requests
 * @property int                               $total_successes        Total successful requests
 * @property int                               $total_failures         Total failed requests
 * @property null|\Illuminate\Support\Carbon   $last_success_at        Timestamp of last successful request
 * @property null|\Illuminate\Support\Carbon   $last_failure_at        Timestamp of last failed request
 * @property null|\Illuminate\Support\Carbon   $opened_at              Timestamp when circuit opened
 * @property null|\Illuminate\Support\Carbon   $closed_at              Timestamp when circuit closed
 * @property \Illuminate\Support\Carbon        $created_at             Creation timestamp
 * @property \Illuminate\Support\Carbon        $updated_at             Last update timestamp
 * @property null|Model                        $context                The polymorphic model this circuit breaker belongs to
 * @property null|Model                        $boundary               The polymorphic boundary scope for this circuit breaker
 *
 * @author Brian Faust <brian@cline.sh>
 */
class CircuitBreaker extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;
    use HasFusePrimaryKey;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * This will be overridden by HasFusePrimaryKey for ULID/UUID configs.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'context_type',
        'context_id',
        'boundary_type',
        'boundary_id',
        'name',
        'state',
        'consecutive_successes',
        'consecutive_failures',
        'total_successes',
        'total_failures',
        'last_success_at',
        'last_failure_at',
        'opened_at',
        'closed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'state' => CircuitBreakerState::class,
        'consecutive_successes' => 'integer',
        'consecutive_failures' => 'integer',
        'total_successes' => 'integer',
        'total_failures' => 'integer',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * Create a new CircuitBreaker model instance.
     *
     * Dynamically sets the table name from configuration.
     *
     * @param array<string, mixed> $attributes Initial attribute values
     */
    public function __construct(array $attributes = [])
    {
        $this->setTable(config('fuse.table_names.circuit_breakers', 'circuit_breakers'));

        parent::__construct($attributes);
    }

    /**
     * Get the polymorphic context this circuit breaker belongs to.
     *
     * Defines the relationship to the model that owns this circuit breaker,
     * such as a User, Team, Organization, or Integration. This allows circuit
     * breakers to be scoped to specific entities in the application.
     *
     * @return MorphTo<Model, $this> The polymorphic relationship to the owning context
     */
    public function context(): MorphTo
    {
        return $this->morphTo('context');
    }

    /**
     * Get the polymorphic boundary this circuit breaker is scoped to.
     *
     * Defines the relationship to the boundary model that this circuit breaker
     * monitors, such as a StripeAccount, SlackWorkspace, or ExternalAPI. This
     * allows circuit breakers to track failures for specific integrations.
     *
     * @return MorphTo<Model, $this> The polymorphic relationship to the boundary scope
     */
    public function boundary(): MorphTo
    {
        return $this->morphTo('boundary');
    }

    /**
     * Define the relationship with circuit breaker events.
     *
     * Returns all events (state transitions, operations) associated with this
     * circuit breaker for audit trail and monitoring purposes.
     *
     * @return HasMany<CircuitBreakerEvent>
     */
    public function events(): HasMany
    {
        /** @var class-string<CircuitBreakerEvent> $eventClass */
        $eventClass = config('fuse.models.circuit_breaker_event', CircuitBreakerEvent::class);

        return $this->hasMany($eventClass);
    }

    /**
     * Scope a query to filter by context.
     *
     * Filters circuit breakers to those belonging to the specified model context.
     * Pass null to get only global circuit breakers (no context).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static> $query   The query builder
     * @param  null|Model                                    $context The context model or null for global
     * @return \Illuminate\Database\Eloquent\Builder<static> The modified query builder
     */
    public function scopeForContext($query, ?Model $context)
    {
        if ($context === null) {
            return $query->whereNull('context_type')->whereNull('context_id');
        }

        return $query->where('context_type', $context->getMorphClass())
            ->where('context_id', $context->getKey());
    }

    /**
     * Scope a query to filter by boundary.
     *
     * Filters circuit breakers to those scoped to the specified boundary model.
     * Pass null to get only circuit breakers without a boundary scope.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static> $query    The query builder
     * @param  null|Model                                    $boundary The boundary model or null for no boundary
     * @return \Illuminate\Database\Eloquent\Builder<static> The modified query builder
     */
    public function scopeForBoundary($query, ?Model $boundary)
    {
        if ($boundary === null) {
            return $query->whereNull('boundary_type')->whereNull('boundary_id');
        }

        return $query->where('boundary_type', $boundary->getMorphClass())
            ->where('boundary_id', $boundary->getKey());
    }

    /**
     * Scope a query to filter only global circuit breakers.
     *
     * Returns only circuit breakers that are not associated with any specific context or boundary.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static> $query The query builder
     * @return \Illuminate\Database\Eloquent\Builder<static> The modified query builder
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('context_type')
            ->whereNull('context_id')
            ->whereNull('boundary_type')
            ->whereNull('boundary_id');
    }

    /**
     * Check if the circuit breaker is in the open state.
     *
     * When open, the circuit breaker rejects all requests immediately without
     * attempting the protected operation, giving the failing service time to recover.
     *
     * @return bool True if the circuit is open
     */
    public function isOpen(): bool
    {
        return $this->state->isOpen();
    }

    /**
     * Check if the circuit breaker is in the closed state.
     *
     * When closed, the circuit breaker allows all requests to proceed normally.
     * This is the healthy operating state.
     *
     * @return bool True if the circuit is closed
     */
    public function isClosed(): bool
    {
        return $this->state->isClosed();
    }

    /**
     * Check if the circuit breaker is in the half-open state.
     *
     * When half-open, the circuit breaker allows a limited number of test requests
     * to determine if the service has recovered before fully closing the circuit.
     *
     * @return bool True if the circuit is half-open
     */
    public function isHalfOpen(): bool
    {
        return $this->state->isHalfOpen();
    }
}
