<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse\Strategies;

use Cline\Fuse\Contracts\Strategy;
use Cline\Fuse\ValueObjects\CircuitBreakerConfiguration;
use Cline\Fuse\ValueObjects\CircuitBreakerMetrics;

final readonly class RollingWindowStrategy implements Strategy
{
    public function shouldOpen(CircuitBreakerMetrics $metrics, CircuitBreakerConfiguration $configuration): bool
    {
        if (! $metrics->hasSufficientThroughput($configuration->minimumThroughput)) {
            return false;
        }

        if ($metrics->lastFailureTime === null) {
            return false;
        }

        $windowStart = time() - $configuration->samplingDuration;

        if ($metrics->lastFailureTime < $windowStart) {
            return false;
        }

        return $metrics->failureRate() >= $configuration->percentageThreshold;
    }
}
