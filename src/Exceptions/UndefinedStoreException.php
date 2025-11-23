<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse\Exceptions;

use InvalidArgumentException;

final class UndefinedStoreException extends InvalidArgumentException
{
    public static function forName(string $name): self
    {
        return new self("Circuit breaker store [{$name}] is not defined.");
    }
}
