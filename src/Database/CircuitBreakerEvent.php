<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse\Database;

use Cline\Fuse\Database\Concerns\HasFusePrimaryKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Circuit breaker event model for audit trail.
 *
 * Represents a single event in a circuit breaker's lifecycle, such as state
 * transitions, successful/failed requests, or manual interventions. Provides
 * a complete audit trail for monitoring and debugging circuit breaker behavior.
 *
 * @property int|string                      $id                    Primary key (int, ULID, or UUID based on config)
 * @property int|string                      $circuit_breaker_id    Foreign key to circuit_breakers table
 * @property string                          $event_type            Type of event (opened, closed, half_opened, success, failure, reset)
 * @property null|array<string, mixed>       $metadata              Additional event context and data
 * @property \Illuminate\Support\Carbon      $created_at            Event timestamp
 *
 * @author Brian Faust <brian@cline.sh>
 */
class CircuitBreakerEvent extends Model
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
     * Indicates if the model should use timestamps.
     *
     * Events only track creation time, not updates.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'circuit_breaker_id',
        'event_type',
        'metadata',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Create a new CircuitBreakerEvent model instance.
     *
     * Dynamically sets the table name from configuration.
     *
     * @param array<string, mixed> $attributes Initial attribute values
     */
    public function __construct(array $attributes = [])
    {
        $this->setTable(config('fuse.table_names.circuit_breaker_events', 'circuit_breaker_events'));

        parent::__construct($attributes);
    }

    /**
     * Define the relationship with the circuit breaker.
     *
     * Returns the circuit breaker that this event belongs to.
     *
     * @return BelongsTo<CircuitBreaker, $this>
     */
    public function circuitBreaker(): BelongsTo
    {
        /** @var class-string<CircuitBreaker> $circuitBreakerClass */
        $circuitBreakerClass = config('fuse.models.circuit_breaker', CircuitBreaker::class);

        return $this->belongsTo($circuitBreakerClass);
    }
}
