<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse\Database;

use Cline\Fuse\Exceptions\MorphKeyViolationException;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Model;

use function array_key_exists;
use function array_merge;

/**
 * Registry for managing polymorphic relationship key mappings.
 *
 * This registry allows you to configure which primary key column should be used
 * for each model type in polymorphic relationships. This is particularly useful
 * when different models use different key types (e.g., User with 'uuid', Team with 'id').
 *
 * @author Brian Faust <brian@cline.sh>
 */
#[Singleton()]
final class ModelRegistry
{
    /**
     * Polymorphic key column mappings.
     *
     * Maps model classes to their primary key column names. This allows
     * different models to use different key types in polymorphic relationships.
     *
     * Example:
     * ```php
     * [
     *     User::class => 'uuid',
     *     Team::class => 'ulid',
     *     Organization::class => 'id',
     * ]
     * ```
     *
     * @var array<class-string, string>
     */
    private array $keyMap = [];

    /**
     * Whether to enforce that all models have a defined key mapping.
     *
     * When true, using a model in a polymorphic relationship without a key mapping
     * will throw a MorphKeyViolationException. When false, defaults to 'id'.
     */
    private bool $enforceKeyMap = false;

    /**
     * Register polymorphic key mappings.
     *
     * Define which primary key column name each model class uses. This allows
     * mixed key types across your polymorphic relationships.
     *
     * ```php
     * $registry->morphKeyMap([
     *     User::class => 'uuid',
     *     Team::class => 'ulid',
     * ]);
     * ```
     *
     * @param array<class-string, string> $map Model class => column name mappings
     */
    public function morphKeyMap(array $map): void
    {
        $this->keyMap = array_merge($this->keyMap, $map);
    }

    /**
     * Register polymorphic key mappings and enforce their usage.
     *
     * Like morphKeyMap(), but also enables strict enforcement. Any model used
     * in a polymorphic relationship without a defined mapping will throw a
     * MorphKeyViolationException, preventing accidental use of unmapped models.
     *
     * ```php
     * $registry->enforceMorphKeyMap([
     *     User::class => 'uuid',
     *     Team::class => 'ulid',
     * ]);
     *
     * // These will work fine
     * Fuse::for($user)->make('api-calls');
     * Fuse::for($team)->make('api-calls');
     *
     * // This will throw MorphKeyViolationException
     * Fuse::for($post)->make('api-calls'); // Post not in mapping
     * ```
     *
     * @param array<class-string, string> $map Model class => column name mappings
     */
    public function enforceMorphKeyMap(array $map): void
    {
        $this->morphKeyMap($map);
        $this->requireKeyMap();
    }

    /**
     * Enable strict enforcement of key mappings.
     *
     * After calling this, all models used in polymorphic relationships must
     * have a defined key mapping or a MorphKeyViolationException will be thrown.
     * Typically used via enforceMorphKeyMap() rather than directly.
     */
    public function requireKeyMap(): void
    {
        $this->enforceKeyMap = true;
    }

    /**
     * Get the polymorphic key column name for a model.
     *
     * Returns the configured key mapping for the model, or falls back to the
     * model's getKeyName() method if no mapping exists. Throws exception if
     * key mapping is required but not defined.
     *
     * ```php
     * $key = $registry->getModelKey($user);
     * // Returns 'uuid' if User mapped to 'uuid'
     * // Returns $user->getKeyName() if not mapped and enforcement disabled
     * // Throws MorphKeyViolationException if not mapped and enforcement enabled
     * ```
     *
     * @param Model $model The model to get the key for
     *
     * @throws MorphKeyViolationException If enforceKeyMap is true and model has no mapping
     *
     * @return string The primary key column name
     */
    public function getModelKey(Model $model): string
    {
        $class = $model::class;

        if (array_key_exists($class, $this->keyMap)) {
            return $this->keyMap[$class];
        }

        if ($this->enforceKeyMap) {
            throw MorphKeyViolationException::forClass($class);
        }

        return $model->getKeyName();
    }

    /**
     * Get the polymorphic key column name from a class string.
     *
     * Similar to getModelKey() but accepts a class string instead of an instance.
     * Useful when you need to determine the key type without instantiating the model.
     *
     * @param class-string $class The model class
     *
     * @throws MorphKeyViolationException If enforceKeyMap is true and class has no mapping
     *
     * @return string The primary key column name
     */
    public function getModelKeyFromClass(string $class): string
    {
        if (array_key_exists($class, $this->keyMap)) {
            return $this->keyMap[$class];
        }

        if ($this->enforceKeyMap) {
            throw MorphKeyViolationException::forClass($class);
        }

        /** @phpstan-ignore-next-line Instantiated class is expected to be Model with getKeyName() */
        return new $class()->getKeyName();
    }

    /**
     * Reset all registry state.
     *
     * Clears all key mappings and disables enforcement. Primarily useful
     * for testing to ensure clean state between test cases.
     */
    public function reset(): void
    {
        $this->keyMap = [];
        $this->enforceKeyMap = false;
    }
}
