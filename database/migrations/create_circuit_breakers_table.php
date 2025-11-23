<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for creating the circuit breakers table.
 *
 * This migration creates the table for storing circuit breaker state, metrics,
 * and lifecycle information. The primary key type (ID, ULID, UUID) is configured
 * via the fuse.primary_key_type configuration option.
 *
 * @see config/fuse.php
 */
return new class() extends Migration
{
    /**
     * Run the migration to create the circuit breakers table.
     *
     * Creates the database schema for storing circuit breaker definitions,
     * state, failure/success counters, and timestamps with support for
     * configurable primary key types.
     */
    public function up(): void
    {
        $primaryKeyType = config('fuse.primary_key_type', 'id');

        Schema::create(config('fuse.table_names.circuit_breakers', 'circuit_breakers'), function (Blueprint $table) use ($primaryKeyType): void {
            match ($primaryKeyType) {
                'ulid' => $table->ulid('id')->primary(),
                'uuid' => $table->uuid('id')->primary(),
                default => $table->id(),
            };

            $table->string('context_type')->nullable()->comment('Polymorphic type of the owning context');
            $table->string('context_id')->nullable()->comment('Polymorphic ID of the owning context');
            $table->string('boundary_type')->nullable()->comment('Polymorphic type of the boundary scope');
            $table->string('boundary_id')->nullable()->comment('Polymorphic ID of the boundary scope');
            $table->string('name')->comment('Circuit breaker name');
            $table->string('state')->default('closed')->comment('Current state: closed, open, half_open');
            $table->unsignedBigInteger('consecutive_successes')->default(0)->comment('Consecutive successful requests');
            $table->unsignedBigInteger('consecutive_failures')->default(0)->comment('Consecutive failed requests');
            $table->unsignedBigInteger('total_successes')->default(0)->comment('Total successful requests');
            $table->unsignedBigInteger('total_failures')->default(0)->comment('Total failed requests');
            $table->timestamp('last_success_at')->nullable()->comment('Timestamp of last successful request');
            $table->timestamp('last_failure_at')->nullable()->comment('Timestamp of last failed request');
            $table->timestamp('opened_at')->nullable()->comment('Timestamp when circuit opened');
            $table->timestamp('closed_at')->nullable()->comment('Timestamp when circuit closed');
            $table->timestamps();

            // Indexes for common query patterns
            $table->index(['context_type', 'context_id'], 'circuit_breakers_context_idx');
            $table->index(['boundary_type', 'boundary_id'], 'circuit_breakers_boundary_idx');
            $table->index('state', 'circuit_breakers_state_idx');
            $table->index('opened_at', 'circuit_breakers_opened_idx');

            // Unique constraint: context + boundary + name combination must be unique
            $table->unique(['context_type', 'context_id', 'boundary_type', 'boundary_id', 'name'], 'circuit_breakers_scope_name_unique');
        });
    }

    /**
     * Reverse the migration by dropping the circuit breakers table.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('fuse.table_names.circuit_breakers', 'circuit_breakers'));
    }
};
