<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse\ValueObjects;

final readonly class CircuitBreakerMetrics
{
    public function __construct(
        public int $consecutiveSuccesses,
        public int $consecutiveFailures,
        public int $totalSuccesses,
        public int $totalFailures,
        public ?int $lastSuccessTime,
        public ?int $lastFailureTime,
    ) {}

    public static function empty(): self
    {
        return new self(
            consecutiveSuccesses: 0,
            consecutiveFailures: 0,
            totalSuccesses: 0,
            totalFailures: 0,
            lastSuccessTime: null,
            lastFailureTime: null,
        );
    }

    public function failureRate(): float
    {
        $total = $this->totalSuccesses + $this->totalFailures;

        if ($total === 0) {
            return 0.0;
        }

        return ($this->totalFailures / $total) * 100;
    }

    public function hasSufficientThroughput(int $minimumThroughput): bool
    {
        return ($this->totalSuccesses + $this->totalFailures) >= $minimumThroughput;
    }
}
