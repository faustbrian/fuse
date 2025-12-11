<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse\Exceptions;

use RuntimeException;

use function sprintf;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class CircuitBreakerOpenException extends RuntimeException
{
    public function __construct(
        public readonly string $name,
        public readonly mixed $fallbackValue = null,
    ) {
        parent::__construct(
            sprintf('Circuit breaker [%s] is open', $name),
        );
    }

    public function hasFallback(): bool
    {
        return $this->fallbackValue !== null;
    }
}
