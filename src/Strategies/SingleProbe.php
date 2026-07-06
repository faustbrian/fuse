<?php

namespace Cline\Fuse\Strategies;

use Cline\Fuse\CircuitBreaker;
use Cline\Fuse\Contracts\RecoveryStrategy;
use Illuminate\Support\Facades\Cache;

/**
 * Recovery strategy that allows one half-open probe at a time.
 *
 * A short-lived cache lock ensures only one worker tests the recovering
 * dependency while the breaker is half-open. Any other jobs are released until
 * that probe either succeeds and closes the breaker or fails and reopens it.
 */
class SingleProbe implements RecoveryStrategy
{
    /**
     * Attempt to acquire the half-open probe lock for this breaker.
     */
    public function allowsAttempt(CircuitBreaker $breaker): bool
    {
        return Cache::lock($breaker->key('probe'), $breaker->probeLeaseSeconds())->get();
    }

    /**
     * Release the probe lock and indicate that one success closes the breaker.
     */
    public function recordSuccess(CircuitBreaker $breaker): bool
    {
        Cache::lock($breaker->key('probe'))->forceRelease();

        return true;
    }

    /**
     * Release the probe lock after a failed recovery attempt.
     */
    public function recordFailure(CircuitBreaker $breaker): void
    {
        Cache::lock($breaker->key('probe'))->forceRelease();
    }
}
