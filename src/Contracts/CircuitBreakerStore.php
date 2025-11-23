<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse\Contracts;

use Cline\Fuse\Enums\CircuitBreakerState;
use Cline\Fuse\ValueObjects\CircuitBreakerMetrics;
use Illuminate\Database\Eloquent\Model;

interface CircuitBreakerStore
{
    public function getState(string $name, ?Model $context = null, ?Model $boundary = null): CircuitBreakerState;

    public function getMetrics(string $name, ?Model $context = null, ?Model $boundary = null): CircuitBreakerMetrics;

    public function recordSuccess(string $name, ?Model $context = null, ?Model $boundary = null): void;

    public function recordFailure(string $name, ?Model $context = null, ?Model $boundary = null): void;

    public function transitionToOpen(string $name, ?Model $context = null, ?Model $boundary = null): void;

    public function transitionToClosed(string $name, ?Model $context = null, ?Model $boundary = null): void;

    public function transitionToHalfOpen(string $name, ?Model $context = null, ?Model $boundary = null): void;

    public function reset(string $name, ?Model $context = null, ?Model $boundary = null): void;
}
