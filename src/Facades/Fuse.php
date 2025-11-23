<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Fuse\Facades;

use Cline\Fuse\CircuitBreaker;
use Cline\Fuse\CircuitBreakerManager;
use Cline\Fuse\Contracts\CircuitBreakerStore;
use Cline\Fuse\Stores\ArrayCircuitBreakerStore;
use Cline\Fuse\Stores\CacheCircuitBreakerStore;
use Cline\Fuse\Stores\DatabaseCircuitBreakerStore;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the Fuse circuit breaker system.
 *
 * @method static CircuitBreaker store(?string $store = null)
 * @method static CircuitBreaker driver(?string $name = null)
 * @method static ArrayCircuitBreakerStore createArrayDriver()
 * @method static CacheCircuitBreakerStore createCacheDriver(array $config)
 * @method static DatabaseCircuitBreakerStore createDatabaseDriver(array $config)
 * @method static CircuitBreaker make(string $name, ?CircuitBreakerStore $store = null, ?string $strategyName = null)
 * @method static string getDefaultDriver()
 * @method static void setDefaultDriver(string $name)
 * @method static CircuitBreakerManager forgetDriver(array|string|null $name = null)
 * @method static CircuitBreakerManager forgetDrivers()
 * @method static CircuitBreakerManager extend(string $driver, Closure $callback)
 *
 * @see CircuitBreakerManager
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class Fuse extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string The service container binding key
     */
    protected static function getFacadeAccessor(): string
    {
        return 'circuit-breaker';
    }
}
