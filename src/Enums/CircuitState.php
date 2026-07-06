<?php

namespace Cline\Fuse\Enums;

/**
 * States in the Fuse circuit breaker lifecycle.
 *
 * `Closed` allows normal traffic, `Open` blocks traffic until the timeout
 * expires, and `HalfOpen` allows controlled probes to test whether the
 * dependency has recovered.
 */
enum CircuitState: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
