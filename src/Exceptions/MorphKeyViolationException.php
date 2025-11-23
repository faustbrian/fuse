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
 * Thrown when a model is used in a polymorphic relationship without a key mapping.
 *
 * This exception is raised when enforceKeyMap is enabled and a model class
 * attempts to participate in a polymorphic relationship without having a
 * registered key mapping in the ModelRegistry.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MorphKeyViolationException extends RuntimeException
{
    /**
     * Create a new exception instance.
     *
     * @param class-string $class The model class missing a key mapping
     */
    public function __construct(string $class)
    {
        parent::__construct(sprintf(
            'Model [%s] does not have a registered key mapping. '.
            'Use ModelRegistry::morphKeyMap() to define the primary key column for this model.',
            $class,
        ));
    }

    /**
     * Create exception for a model class without a key mapping.
     *
     * @param  class-string $class The model class missing a key mapping
     * @return self         The exception instance
     */
    public static function forClass(string $class): self
    {
        return new self($class);
    }
}
