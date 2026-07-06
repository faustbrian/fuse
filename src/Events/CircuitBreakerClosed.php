<?php

namespace Cline\Fuse\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a service breaker returns to normal traffic flow.
 *
 * This may happen after successful half-open recovery or after a manual close
 * command, so listeners should treat it as the canonical "service restored"
 * event regardless of who initiated the transition.
 */
class CircuitBreakerClosed
{
    use Dispatchable, SerializesModels;

    /**
     * @param  string  $service  Configured service whose breaker was closed
     */
    public function __construct(
        public readonly string $service
    ) {}
}
