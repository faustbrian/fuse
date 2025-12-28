<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse;

use Cline\Fuse\Contracts\CircuitBreakerStore;
use Cline\Fuse\Database\CircuitBreaker;
use Cline\Fuse\Database\CircuitBreakerEvent;
use Cline\Fuse\Database\ModelRegistry;
use Cline\VariableKeys\Enums\PrimaryKeyType;
use Cline\VariableKeys\Facades\VariableKeys;
use Illuminate\Container\Container;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Laravel\Octane\Contracts\OperationTerminated;
use Override;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

use function interface_exists;

/**
 * Service provider for the Fuse circuit breaker package.
 *
 * Registers the circuit breaker manager, publishes configuration and migrations,
 * and sets up event listeners for cache clearing in long-running processes.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class FuseServiceProvider extends PackageServiceProvider
{
    /**
     * Configure the package settings.
     *
     * Defines package configuration including the package name, config file,
     * and database migrations for circuit breaker storage tables.
     *
     * @param Package $package The package configuration instance to configure
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('fuse')
            ->hasConfigFile()
            ->hasMigrations(['create_fuse_tables']);
    }

    /**
     * Register the package's services in the container.
     *
     * CircuitBreakerManager uses #[Singleton] attribute for automatic singleton registration.
     * Registers the default store based on configuration.
     */
    #[Override()]
    public function registeringPackage(): void
    {
        // Register models with VariableKeys for primary key type management
        VariableKeys::map([
            CircuitBreaker::class => [
                'primary_key_type' => PrimaryKeyType::from(Config::get('fuse.primary_key_type', 'id')),
            ],
            CircuitBreakerEvent::class => [
                'primary_key_type' => PrimaryKeyType::from(Config::get('fuse.primary_key_type', 'id')),
            ],
        ]);

        // Register ModelRegistry singleton (also has #[Singleton] attribute)
        // and configure morph key mappings for both context and boundary
        $this->app->resolving(ModelRegistry::class, function (ModelRegistry $registry): void {
            // Context mappings (WHO is using it)
            $morphKeyMap = Config::get('fuse.morphKeyMap', []);
            $enforceMorphKeyMap = Config::get('fuse.enforceMorphKeyMap', []);

            if (!empty($enforceMorphKeyMap)) {
                $registry->enforceMorphKeyMap($enforceMorphKeyMap);
            } elseif (!empty($morphKeyMap)) {
                $registry->morphKeyMap($morphKeyMap);
            }

            // Boundary mappings (WHAT they're doing it with)
            $boundaryMorphKeyMap = Config::get('fuse.boundaryMorphKeyMap', []);
            $enforceBoundaryMorphKeyMap = Config::get('fuse.enforceBoundaryMorphKeyMap', []);

            if (!empty($enforceBoundaryMorphKeyMap)) {
                $registry->enforceMorphKeyMap($enforceBoundaryMorphKeyMap);
            } elseif (!empty($boundaryMorphKeyMap)) {
                $registry->morphKeyMap($boundaryMorphKeyMap);
            }
        });

        // Register CircuitBreakerStore based on default store config
        $this->app->singleton(function (Container $app): CircuitBreakerStore {
            /** @var CircuitBreakerManager $manager */
            $manager = $app->make(CircuitBreakerManager::class);

            $defaultDriver = $manager->getDefaultDriver();
            $config = Config::get('fuse.stores.'.$defaultDriver, []);

            return match ($config['driver'] ?? 'cache') {
                'array' => $manager->createArrayDriver(),
                'cache' => $manager->createCacheDriver($config),
                'database' => $manager->createDatabaseDriver($config),
                default => $manager->createCacheDriver($config),
            };
        });

        // Register CircuitBreakerManager as singleton with 'circuit-breaker' alias
        $this->app->singleton('circuit-breaker', CircuitBreakerManager::class);
    }

    /**
     * Bootstrap the package's services.
     *
     * Sets up event listeners for automatic cache management in long-running processes
     * and registers model observers if enabled in configuration.
     */
    #[Override()]
    public function bootingPackage(): void
    {
        $this->listenForEvents();
    }

    /**
     * Listen for events relevant to circuit breaker cache management.
     *
     * Sets up event listeners to clear state caches when appropriate.
     * This ensures circuit breakers are re-evaluated in long-running processes (Octane)
     * and after queue job processing, preventing stale circuit breaker state in
     * persistent application states.
     *
     * Laravel Octane keeps the application in memory between requests, which
     * means cached circuit breaker state could persist across different requests.
     * Queue jobs may also have cached state from earlier in the process.
     */
    private function listenForEvents(): void
    {
        // Laravel Octane support - reset state after operations complete
        if (Config::get('fuse.register_octane_reset_listener', true) && interface_exists(OperationTerminated::class)) {
            Event::listen(fn (OperationTerminated $event) => $this->resetCircuitBreakerState());
        }

        // Queue support - reset state after job processing
        Event::listen([
            JobProcessed::class,
        ], fn () => $this->resetCircuitBreakerState());
    }

    /**
     * Reset circuit breaker state.
     *
     * This method can be extended in the future to implement actual state
     * clearing if needed for array stores or in-memory caching.
     */
    private function resetCircuitBreakerState(): void
    {
        // For now, cache and database stores manage their own persistence
        // Array store is request-scoped and will be garbage collected
        // Future: Add explicit reset methods if needed
    }
}
