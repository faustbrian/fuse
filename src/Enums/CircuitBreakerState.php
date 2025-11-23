<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse\Enums;

enum CircuitBreakerState: string
{
    case CLOSED = 'closed';
    case OPEN = 'open';
    case HALF_OPEN = 'half_open';

    public function isClosed(): bool
    {
        return $this === self::CLOSED;
    }

    public function isOpen(): bool
    {
        return $this === self::OPEN;
    }

    public function isHalfOpen(): bool
    {
        return $this === self::HALF_OPEN;
    }

    public function canAttemptRequest(): bool
    {
        return $this->isClosed() || $this->isHalfOpen();
    }

    public function shouldRejectRequest(): bool
    {
        return $this->isOpen();
    }
}
