<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for creating the circuit breaker events table.
 *
 * This migration creates the table for storing an audit trail of circuit breaker
 * events such as state transitions, successes, failures, and manual interventions.
 * The primary key type (ID, ULID, UUID) is configured via the fuse.primary_key_type
 * configuration option.
 *
 * @see config/fuse.php
 */
return new class() extends Migration
{
    /**
     * Run the migration to create the circuit breaker events table.
     *
     * Creates the database schema for storing circuit breaker event audit trail
     * with support for configurable primary key types and JSON metadata storage.
     */
    public function up(): void
    {
        $primaryKeyType = config('fuse.primary_key_type', 'id');
        $connection = config('fuse.stores.database.connection') ?? config('database.default');
        $useJsonb = DB::connection($connection)->getDriverName() === 'pgsql';

        Schema::create(config('fuse.table_names.circuit_breaker_events', 'circuit_breaker_events'), function (Blueprint $table) use ($primaryKeyType, $useJsonb): void {
            match ($primaryKeyType) {
                'ulid' => $table->ulid('id')->primary(),
                'uuid' => $table->uuid('id')->primary(),
                default => $table->id(),
            };

            // Foreign key to circuit_breakers table
            $circuitBreakersTable = config('fuse.table_names.circuit_breakers', 'circuit_breakers');
            match ($primaryKeyType) {
                'ulid' => $table->foreignUlid('circuit_breaker_id')->constrained($circuitBreakersTable)->cascadeOnDelete(),
                'uuid' => $table->foreignUuid('circuit_breaker_id')->constrained($circuitBreakersTable)->cascadeOnDelete(),
                default => $table->foreignId('circuit_breaker_id')->constrained($circuitBreakersTable)->cascadeOnDelete(),
            };

            $table->string('event_type')->comment('Event type: opened, closed, half_opened, success, failure, reset');
            $useJsonb
                ? $table->jsonb('metadata')->nullable()->comment('Additional event context and data')
                : $table->json('metadata')->nullable()->comment('Additional event context and data');
            $table->timestamp('created_at')->useCurrent()->comment('Event timestamp');

            // Indexes for common query patterns
            $table->index('circuit_breaker_id', 'circuit_breaker_events_cb_id_idx');
            $table->index('event_type', 'circuit_breaker_events_type_idx');
            $table->index('created_at', 'circuit_breaker_events_created_idx');
            $table->index(['circuit_breaker_id', 'event_type'], 'circuit_breaker_events_cb_type_idx');

            if ($useJsonb) {
                // PostgreSQL GIN index for efficient JSON queries
                $table->index('metadata', 'circuit_breaker_events_metadata_gin', 'gin');
            }
        });
    }

    /**
     * Reverse the migration by dropping the circuit breaker events table.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('fuse.table_names.circuit_breaker_events', 'circuit_breaker_events'));
    }
};
