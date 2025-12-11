<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse\Contracts;

use Cline\Fuse\ValueObjects\CircuitBreakerConfiguration;
use Cline\Fuse\ValueObjects\CircuitBreakerMetrics;

/**
 * @author Brian Faust <brian@cline.sh>
 */
interface Strategy
{
    public function shouldOpen(CircuitBreakerMetrics $metrics, CircuitBreakerConfiguration $configuration): bool;
}
