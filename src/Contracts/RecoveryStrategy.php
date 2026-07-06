<?php

namespace Cline\Fuse\Contracts;

use Cline\Fuse\CircuitBreaker;

/**
 * Coordinates how a breaker probes recovery while half-open.
 *
 * Strategies control how many jobs may test a recovering dependency, how
 * successes are interpreted, and what cleanup is required when recovery fails.
 */
interface RecoveryStrategy
{
    /**
     * Decide whether this caller may act as the current recovery probe.
     *
     * The breaker is already half-open when this is called. Implementations
     * typically use locks or counters to limit concurrent probe traffic.
     */
    public function allowsAttempt(CircuitBreaker $breaker): bool;

    /**
     * Record a successful half-open attempt.
     *
     * Returning `true` tells the breaker it has enough evidence to close fully.
     * Returning `false` leaves the breaker half-open so more probes can run.
     */
    public function recordSuccess(CircuitBreaker $breaker): bool;

    /**
     * Clean up any recovery coordination after a counted probe failure.
     */
    public function recordFailure(CircuitBreaker $breaker): void;
}
