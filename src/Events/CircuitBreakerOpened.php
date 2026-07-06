<?php

namespace Cline\Fuse\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when Fuse trips a breaker open for a service.
 *
 * The event carries the failure statistics that triggered the transition so
 * listeners can log, notify, or correlate the outage without re-reading cache.
 */
class CircuitBreakerOpened
{
    use Dispatchable, SerializesModels;

    /**
     * @param  string  $service  Configured service whose breaker opened
     * @param  float  $failureRate  Failure percentage observed when opening
     * @param  int  $attempts  Request count in the triggering window
     * @param  int  $failures  Failure count in the triggering window
     */
    public function __construct(
        public readonly string $service,
        public readonly float $failureRate = 0,
        public readonly int $attempts = 0,
        public readonly int $failures = 0
    ) {}
}
