<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse;

use Cline\Fuse\Contracts\CircuitBreakerStore;
use Cline\Fuse\Contracts\Strategy;
use Cline\Fuse\Database\ModelRegistry;
use Cline\Fuse\Exceptions\UndefinedStoreException;
use Cline\Fuse\Exceptions\UnsupportedDriverException;
use Cline\Fuse\Stores\ArrayCircuitBreakerStore;
use Cline\Fuse\Stores\CacheCircuitBreakerStore;
use Cline\Fuse\Stores\DatabaseCircuitBreakerStore;
use Cline\Fuse\ValueObjects\CircuitBreakerConfiguration;
use Closure;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

use function array_key_exists;
use function assert;
use function is_array;
use function is_string;
use function method_exists;
use function sprintf;
use function throw_if;
use function throw_unless;
use function ucfirst;

/**
 * Central manager for circuit breaker stores and driver instances.
 *
 * This class manages multiple circuit breaker storage drivers, handles configuration,
 * and provides a unified interface for working with circuit breakers across the application.
 *
 * @author Brian Faust <brian@cline.sh>
 */
#[Singleton()]
final class CircuitBreakerManager
{
    /**
     * The array of resolved circuit breaker stores.
     *
     * Caches instantiated CircuitBreaker instances by store name to avoid recreating
     * them on each access. Each store corresponds to a configured driver in fuse.stores.
     *
     * @var array<string, CircuitBreaker>
     */
    private array $stores = [];

    /**
     * The registered custom driver creators.
     *
     * Maps driver type names to closure factories for creating custom driver implementations.
     * Allows extending the circuit breaker system with custom storage backends beyond array/cache.
     *
     * @var array<string, Closure>
     */
    private array $customCreators = [];

    /**
     * The polymorphic context for circuit breakers.
     *
     * When set, circuit breakers will be scoped to this specific model instance,
     * allowing per-tenant, per-user, or per-integration circuit breakers.
     */
    private ?Model $context = null;

    /**
     * The polymorphic boundary for circuit breakers.
     *
     * When set, circuit breakers will be scoped to this specific boundary model,
     * allowing tracking of failures for specific integrations or external services.
     */
    private ?Model $boundary = null;

    /**
     * Create a new circuit breaker manager instance.
     *
     * @param Container $container the Laravel service container used for dependency injection,
     *                             resolving driver instances, and creating strategy class instances
     */
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Dynamically call the default store instance.
     *
     * @param  string            $method     The method name to call
     * @param  array<int, mixed> $parameters The method parameters
     * @return mixed             The result of the method call
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->store()->{$method}(...$parameters);
    }

    /**
     * Set the polymorphic context for circuit breakers.
     *
     * Scopes all subsequent circuit breaker operations to the specified model context.
     * This enables per-tenant, per-user, or per-integration circuit breakers.
     *
     * ```php
     * // Per user circuit breaker
     * Fuse::for($user)->make('api-calls');
     *
     * // Per tenant circuit breaker
     * Fuse::for($tenant)->make('email-service');
     *
     * // Global circuit breaker (no context)
     * Fuse::make('shared-service');
     * ```
     *
     * @param  null|Model $context The context model or null for global
     * @return static     A new manager instance with the context set
     */
    public function for(?Model $context): static
    {
        $instance = clone $this;
        $instance->context = $context;

        return $instance;
    }

    /**
     * Set the polymorphic boundary for circuit breakers.
     *
     * Scopes all subsequent circuit breaker operations to the specified boundary model.
     * This enables tracking failures for specific integrations or external services.
     *
     * ```php
     * // User-specific circuit breaker for a Stripe account
     * Fuse::for($user)->boundary($stripeAccount)->make('charges');
     *
     * // Tenant-specific circuit breaker for their Slack workspace
     * Fuse::for($tenant)->boundary($slackWorkspace)->make('notifications');
     *
     * // Global circuit breaker for an external API
     * Fuse::boundary($externalApi)->make('data-sync');
     * ```
     *
     * @param  null|Model $boundary The boundary model or null for no boundary
     * @return static     A new manager instance with the boundary set
     */
    public function boundary(?Model $boundary): static
    {
        $instance = clone $this;
        $instance->boundary = $boundary;

        return $instance;
    }

    /**
     * Get a circuit breaker store instance.
     *
     * @param null|string $store The store name, or null to use the default
     *
     * @throws InvalidArgumentException If the store is not defined
     *
     * @return CircuitBreaker The circuit breaker instance
     */
    public function store(?string $store = null): CircuitBreaker
    {
        return $this->driver($store);
    }

    /**
     * Get a circuit breaker store instance by name.
     *
     * @param null|string $name The driver name, or null to use the default
     *
     * @throws InvalidArgumentException If the driver is not defined or supported
     *
     * @return CircuitBreaker The circuit breaker instance
     */
    public function driver(?string $name = null): CircuitBreaker
    {
        $name = $name ?: $this->getDefaultDriver();

        return $this->stores[$name] = $this->get($name);
    }

    /**
     * Create an instance of the array driver.
     *
     * @return ArrayCircuitBreakerStore The created array store instance
     */
    public function createArrayDriver(): ArrayCircuitBreakerStore
    {
        return new ArrayCircuitBreakerStore();
    }

    /**
     * Create an instance of the cache driver.
     *
     * @param  array<string, mixed>     $config The driver configuration
     * @return CacheCircuitBreakerStore The created cache store instance
     */
    public function createCacheDriver(array $config): CacheCircuitBreakerStore
    {
        $cacheStore = $config['store'] ?? null;
        $prefix = $config['prefix'] ?? 'circuit_breaker';

        $cache = $cacheStore !== null
            ? $this->container->make(Factory::class)->store($cacheStore)
            : $this->container->make(CacheRepository::class);

        return new CacheCircuitBreakerStore($cache, $prefix);
    }

    /**
     * Create an instance of the database driver.
     *
     * @param  array<string, mixed>        $config The driver configuration
     * @return DatabaseCircuitBreakerStore The created database store instance
     */
    public function createDatabaseDriver(array $config): DatabaseCircuitBreakerStore
    {
        $connection = $config['connection'] ?? null;

        return new DatabaseCircuitBreakerStore(
            connection: $connection,
            modelRegistry: $this->container->make(ModelRegistry::class),
        );
    }

    /**
     * Create a circuit breaker instance with the given configuration.
     *
     * @param  string                   $name         Circuit breaker name
     * @param  null|CircuitBreakerStore $store        Optional store override
     * @param  null|string              $strategyName Optional strategy name override
     * @return CircuitBreaker           The configured circuit breaker instance
     */
    public function make(
        string $name,
        ?CircuitBreakerStore $store = null,
        ?string $strategyName = null,
    ): CircuitBreaker {
        $config = CircuitBreakerConfiguration::fromDefaults($name);

        if ($strategyName !== null) {
            $config = $config->withStrategy($strategyName);
        }

        $store ??= $this->resolveStore();
        $strategy = $this->resolveStrategy($config->strategy);

        return new CircuitBreaker($config, $store, $strategy, $this->context, $this->boundary);
    }

    /**
     * Get the default store name.
     *
     * @return string The default driver name
     */
    public function getDefaultDriver(): string
    {
        $default = Config::get('fuse.default', 'cache');
        assert(is_string($default));

        return $default;
    }

    /**
     * Set the default store name.
     *
     * @param string $name The default driver name
     */
    public function setDefaultDriver(string $name): void
    {
        Config::set(['fuse.default' => $name]);
    }

    /**
     * Unset the given store instances.
     *
     * @param  null|array<int, string>|string $name The driver name(s) to forget, or null for default
     * @return static                         Fluent interface for method chaining
     */
    public function forgetDriver(array|string|null $name = null): static
    {
        $name ??= $this->getDefaultDriver();

        foreach ((array) $name as $storeName) {
            if (!array_key_exists($storeName, $this->stores)) {
                continue;
            }

            unset($this->stores[$storeName]);
        }

        return $this;
    }

    /**
     * Forget all of the resolved store instances.
     *
     * @return static Fluent interface for method chaining
     */
    public function forgetDrivers(): static
    {
        $this->stores = [];

        return $this;
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @param  string  $driver   The driver name
     * @param  Closure $callback The creator callback
     * @return static  Fluent interface for method chaining
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customCreators[$driver] = $callback->bindTo($this, $this);

        return $this;
    }

    /**
     * Attempt to get the store from the local cache.
     *
     * @param  string         $name The store name
     * @return CircuitBreaker The circuit breaker instance
     */
    private function get(string $name): CircuitBreaker
    {
        return $this->stores[$name] ?? $this->resolve($name);
    }

    /**
     * Resolve the given store.
     *
     * Creates a new driver instance based on configuration and returns a circuit breaker.
     *
     * @param string $name The store name
     *
     * @throws InvalidArgumentException If the store is not defined or the driver is not supported
     *
     * @return CircuitBreaker The resolved circuit breaker instance
     */
    private function resolve(string $name): CircuitBreaker
    {
        $config = $this->getConfig($name);

        throw_if($config === null, UndefinedStoreException::forName($name));

        assert(is_string($config['driver']));

        $store = $this->createStoreDriver($config);

        return $this->make($name, $store);
    }

    /**
     * Create a store driver instance.
     *
     * @param array<string, mixed> $config The driver configuration
     *
     * @throws UnsupportedDriverException If the driver is not supported
     *
     * @return CircuitBreakerStore The created store instance
     */
    private function createStoreDriver(array $config): CircuitBreakerStore
    {
        assert(is_string($config['driver']));

        if (array_key_exists($config['driver'], $this->customCreators)) {
            return $this->callCustomCreator($config);
        }

        $driverMethod = 'create'.ucfirst($config['driver']).'Driver';

        if (method_exists($this, $driverMethod)) {
            return $this->{$driverMethod}($config);
        }

        throw UnsupportedDriverException::forDriver($config['driver']);
    }

    /**
     * Call a custom driver creator.
     *
     * @param  array<string, mixed> $config The driver configuration
     * @return CircuitBreakerStore  The created store instance
     */
    private function callCustomCreator(array $config): CircuitBreakerStore
    {
        assert(is_string($config['driver']));

        return $this->customCreators[$config['driver']]($this->container, $config);
    }

    /**
     * Get the circuit breaker store configuration.
     *
     * @param  string                    $name The store name
     * @return null|array<string, mixed> The store configuration, or null if not found
     */
    private function getConfig(string $name): ?array
    {
        /** @var null|array<string, mixed> $config */
        $config = Config::get('fuse.stores.'.$name);

        return is_array($config) ? $config : null;
    }

    /**
     * Resolve the store for the default driver.
     *
     * @return CircuitBreakerStore The store instance
     */
    private function resolveStore(): CircuitBreakerStore
    {
        $name = $this->getDefaultDriver();
        $config = $this->getConfig($name);

        throw_if($config === null, UndefinedStoreException::forName($name));

        return $this->createStoreDriver($config);
    }

    /**
     * Resolve a strategy instance by name.
     *
     * @param string $name The strategy name
     *
     * @throws InvalidArgumentException If the strategy is not defined
     * @return Strategy                 The resolved strategy instance
     */
    private function resolveStrategy(string $name): Strategy
    {
        $strategies = Config::get('fuse.strategies.available', []);

        throw_unless(isset($strategies[$name]), InvalidArgumentException::class, sprintf('Strategy [%s] is not defined.', $name));

        return $this->container->make($strategies[$name]);
    }
}
