<?php

namespace Cline\Fuse\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when an open breaker begins its recovery-probe phase.
 *
 * Listeners can use this to observe that Fuse has started testing whether the
 * downstream dependency is healthy enough to resume normal traffic.
 */
class CircuitBreakerHalfOpen
{
    use Dispatchable, SerializesModels;

    /**
     * @param  string  $service  Configured service whose breaker entered half-open
     */
    public function __construct(
        public readonly string $service
    ) {}
}
