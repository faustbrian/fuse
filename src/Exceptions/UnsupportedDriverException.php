<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse\Exceptions;

use InvalidArgumentException;

use function sprintf;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class UnsupportedDriverException extends InvalidArgumentException
{
    public static function forDriver(string $driver): self
    {
        return new self(sprintf('Circuit breaker driver [%s] is not supported.', $driver));
    }
}
