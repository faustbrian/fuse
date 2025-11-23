<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse\Events;

use Cline\Fuse\Enums\CircuitBreakerState;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class CircuitBreakerRequestSucceeded
{
    use Dispatchable;

    public function __construct(
        public string $name,
        public CircuitBreakerState $state,
    ) {}
}
