<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse\ValueObjects;

use function config;

/**
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class CircuitBreakerConfiguration
{
    public function __construct(
        public string $name,
        public int $failureThreshold,
        public int $successThreshold,
        public int $timeout,
        public int $samplingDuration,
        public int $minimumThroughput,
        public int $percentageThreshold,
        public string $strategy,
    ) {}

    public static function fromDefaults(string $name): self
    {
        return new self(
            name: $name,
            failureThreshold: (int) config('fuse.defaults.failure_threshold', 5),
            successThreshold: (int) config('fuse.defaults.success_threshold', 2),
            timeout: (int) config('fuse.defaults.timeout', 60),
            samplingDuration: (int) config('fuse.defaults.sampling_duration', 120),
            minimumThroughput: (int) config('fuse.defaults.minimum_throughput', 10),
            percentageThreshold: (int) config('fuse.defaults.percentage_threshold', 50),
            strategy: (string) config('fuse.strategies.default', 'consecutive_failures'),
        );
    }

    public function withFailureThreshold(int $threshold): self
    {
        return new self(
            name: $this->name,
            failureThreshold: $threshold,
            successThreshold: $this->successThreshold,
            timeout: $this->timeout,
            samplingDuration: $this->samplingDuration,
            minimumThroughput: $this->minimumThroughput,
            percentageThreshold: $this->percentageThreshold,
            strategy: $this->strategy,
        );
    }

    public function withSuccessThreshold(int $threshold): self
    {
        return new self(
            name: $this->name,
            failureThreshold: $this->failureThreshold,
            successThreshold: $threshold,
            timeout: $this->timeout,
            samplingDuration: $this->samplingDuration,
            minimumThroughput: $this->minimumThroughput,
            percentageThreshold: $this->percentageThreshold,
            strategy: $this->strategy,
        );
    }

    public function withTimeout(int $seconds): self
    {
        return new self(
            name: $this->name,
            failureThreshold: $this->failureThreshold,
            successThreshold: $this->successThreshold,
            timeout: $seconds,
            samplingDuration: $this->samplingDuration,
            minimumThroughput: $this->minimumThroughput,
            percentageThreshold: $this->percentageThreshold,
            strategy: $this->strategy,
        );
    }

    public function withStrategy(string $strategy): self
    {
        return new self(
            name: $this->name,
            failureThreshold: $this->failureThreshold,
            successThreshold: $this->successThreshold,
            timeout: $this->timeout,
            samplingDuration: $this->samplingDuration,
            minimumThroughput: $this->minimumThroughput,
            percentageThreshold: $this->percentageThreshold,
            strategy: $strategy,
        );
    }
}
