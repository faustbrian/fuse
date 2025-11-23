# Octane Support

Laravel Octane keeps your application in memory between requests, requiring special handling for circuit breaker state. Fuse provides full Octane support out of the box.

## The Challenge

In traditional Laravel apps, the application bootstraps fresh for each request. With Octane, the application stays in memory, which affects circuit breaker behavior:

**Without Octane (traditional):**
```
Request 1 → Bootstrap → Circuit Breaker State → End Request → Destroy
Request 2 → Bootstrap → Circuit Breaker State → End Request → Destroy
```

**With Octane:**
```
Bootstrap Once
Request 1 → Circuit Breaker State (memory)
Request 2 → Circuit Breaker State (same memory!)
Request 3 → Circuit Breaker State (same memory!)
```

## Automatic State Reset

Fuse automatically resets in-memory state between Octane requests.

### Enable Auto-Reset

Enabled by default in `config/fuse.php`:

```php
'register_octane_reset_listener' => env('FUSE_REGISTER_OCTANE_RESET_LISTENER', true),
```

```env
FUSE_REGISTER_OCTANE_RESET_LISTENER=true
```

### How It Works

Fuse listens for Octane's `OperationTerminated` event and flushes internal caches:

```php
// Automatically registered when enabled
Event::listen(OperationTerminated::class, function () {
    // Clears any in-memory state
    Fuse::flush();
});
```

## Storage Driver Considerations

### Array Driver

**Not recommended for Octane:**
- State persists across requests in the same worker
- Not shared between workers
- Can lead to inconsistent behavior

```php
// ❌ Bad: Array driver with Octane
Fuse::store('array')->make('api')->call($callable);
```

### Cache Driver (Recommended)

**Perfect for Octane:**
- State stored in Redis/Memcached
- Shared across all workers
- Consistent behavior

```php
// ✅ Good: Cache driver with Octane
Fuse::store('cache')->make('api')->call($callable);
```

### Database Driver

**Works with Octane:**
- State persists in database
- Shared across workers
- Slightly slower than cache

```php
// ✅ Good: Database driver with Octane
Fuse::store('database')->make('api')->call($callable);
```

## Configuration for Octane

### Recommended Setup

```env
# Use cache for best performance
FUSE_STORE=cache
FUSE_CACHE_STORE=redis

# Enable auto-reset
FUSE_REGISTER_OCTANE_RESET_LISTENER=true

# Optimize event dispatching
FUSE_EVENTS_ENABLED=true
```

### Redis Configuration

```php
// config/database.php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),

    'options' => [
        'cluster' => env('REDIS_CLUSTER', 'redis'),
        'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
    ],

    'default' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_DB', '0'),
    ],

    // Dedicated connection for circuit breakers
    'circuit_breakers' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_CIRCUIT_BREAKERS_DB', '1'),
    ],
],

// config/cache.php
'stores' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'circuit_breakers',
    ],
],
```

## Worker Isolation

Each Octane worker operates independently:

### Understanding Workers

```
Worker 1 → In-memory state → Cache/DB
Worker 2 → In-memory state → Cache/DB
Worker 3 → In-memory state → Cache/DB
Worker 4 → In-memory state → Cache/DB
```

State is synchronized through Cache/DB, not worker memory.

### Worker-Specific Issues

```php
// ❌ Bad: Singleton pattern with array store
class ApiService
{
    private static $breaker;

    public function call()
    {
        if (!self::$breaker) {
            self::$breaker = Fuse::store('array')->make('api');
        }

        return self::$breaker->call($this->fetchData(...));
    }
}
```

Problem: `$breaker` persists across requests in the same worker.

```php
// ✅ Good: Create breaker per request
class ApiService
{
    public function call()
    {
        $breaker = Fuse::store('cache')->make('api');

        return $breaker->call($this->fetchData(...));
    }
}
```

## Testing with Octane

### Warm Cache Before Testing

```php
use Orchestra\Testbench\TestCase;
use Cline\Fuse\Facades\Fuse;

class OctaneCircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Use cache driver for tests
        config(['fuse.default' => 'cache']);

        // Clear cache before each test
        Cache::flush();
    }

    public function test_circuit_breaker_state_persists_across_requests()
    {
        // Simulate first request
        $breaker = Fuse::make('api');

        for ($i = 0; $i < 5; $i++) {
            try {
                $breaker->call(fn () => throw new \Exception());
            } catch (\Exception $e) {}
        }

        $this->assertTrue($breaker->getState()->isOpen());

        // Simulate Octane reset (automatic in real Octane)
        Fuse::flush();

        // Simulate second request (state should still be open in cache)
        $breaker2 = Fuse::make('api');
        $this->assertTrue($breaker2->getState()->isOpen());
    }
}
```

## Manual State Management

### Custom Reset Logic

Sometimes you need custom reset behavior:

```php
use Laravel\Octane\Contracts\OperationTerminated;

// In a service provider
Event::listen(OperationTerminated::class, function () {
    // Custom cleanup logic
    $this->resetCircuitBreakerContext();
    $this->clearRequestSpecificData();

    // Let Fuse handle its own cleanup
    Fuse::flush();
});
```

### Selective State Preservation

```php
Event::listen(OperationTerminated::class, function () {
    // Reset everything except specific circuits
    $preserve = ['critical-payment-api', 'auth-service'];

    $circuits = Fuse::all(); // Hypothetical method

    foreach ($circuits as $circuit) {
        if (!in_array($circuit->name, $preserve)) {
            // Reset this circuit
        }
    }
});
```

## Performance Optimization

### Connection Pooling

Use persistent Redis connections:

```php
// config/database.php
'redis' => [
    'client' => 'phpredis',

    'options' => [
        'persistent' => true,
    ],

    // ... other config
],
```

### Reduce Event Overhead

For high-throughput scenarios:

```php
// Disable events in production if not needed
'events' => [
    'enabled' => env('FUSE_EVENTS_ENABLED', false),
],
```

### Cache Metrics

Cache metric calculations:

```php
public function getCircuitMetrics(string $name)
{
    return Cache::remember("circuit:metrics:{$name}", 5, function () use ($name) {
        return Fuse::make($name)->getMetrics();
    });
}
```

## Monitoring Octane Workers

### Worker Health Check

```php
Route::get('/health/octane', function () {
    // Check if circuit breakers are working correctly
    $testBreaker = Fuse::make('health-check');

    try {
        $result = $testBreaker->call(fn () => true);

        return response()->json([
            'status' => 'healthy',
            'octane' => true,
            'worker_id' => getmypid(),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'unhealthy',
            'error' => $e->getMessage(),
            'worker_id' => getmypid(),
        ], 500);
    }
});
```

### Per-Worker Metrics

```php
Event::listen(CircuitBreakerOpened::class, function ($event) {
    Log::info('Circuit opened', [
        'circuit' => $event->name,
        'worker_id' => getmypid(),
        'memory' => memory_get_usage(true),
    ]);
});
```

## Common Pitfalls

### 1. Static Properties

```php
// ❌ Bad: Static state persists
class BadService
{
    private static $circuitState = [];

    public function call()
    {
        // This state persists across requests!
    }
}
```

```php
// ✅ Good: Use cache/database
class GoodService
{
    public function call()
    {
        $breaker = Fuse::store('cache')->make('api');
        // State properly managed
    }
}
```

### 2. Object Reuse

```php
// ❌ Bad: Reusing breaker instance
class BadController
{
    private $breaker;

    public function __construct()
    {
        $this->breaker = Fuse::make('api'); // Created once per worker
    }

    public function handle()
    {
        return $this->breaker->call($callable);
    }
}
```

```php
// ✅ Good: Create per request
class GoodController
{
    public function handle()
    {
        $breaker = Fuse::make('api'); // Created per request
        return $breaker->call($callable);
    }
}
```

### 3. Array Driver Usage

```php
// ❌ Bad: Array store doesn't share state
config(['fuse.default' => 'array']);

// ✅ Good: Use cache or database
config(['fuse.default' => 'cache']);
```

## Swoole vs RoadRunner

Fuse works identically with both Octane servers:

### Swoole

```env
OCTANE_SERVER=swoole
```

### RoadRunner

```env
OCTANE_SERVER=roadrunner
```

No configuration changes needed - Fuse handles both automatically.

## Debugging Octane Issues

### Enable Debug Logging

```php
Event::listen(OperationTerminated::class, function () {
    Log::debug('Octane request ended', [
        'worker_id' => getmypid(),
        'memory_peak' => memory_get_peak_usage(true),
    ]);

    Fuse::flush();
});
```

### Check State Synchronization

```php
public function debugCircuitState(string $name)
{
    $breaker = Fuse::make($name);

    return [
        'worker_id' => getmypid(),
        'state' => $breaker->getState()->name,
        'metrics' => $breaker->getMetrics(),
        'cache_value' => Cache::get("circuit_breaker:{$name}:state"),
    ];
}
```

## Production Checklist

- [ ] Use cache or database driver (not array)
- [ ] Configure Redis for circuit breakers
- [ ] Enable auto-reset listener
- [ ] Test with multiple workers
- [ ] Monitor memory usage
- [ ] Verify state synchronization
- [ ] Set up health checks
- [ ] Configure connection pooling
- [ ] Review event dispatch overhead
- [ ] Test worker failover scenarios

## Example Octane Configuration

Complete production setup:

```php
// config/octane.php
return [
    'server' => env('OCTANE_SERVER', 'roadrunner'),

    'warm' => [
        // Warm circuit breaker configuration
        \Cline\Fuse\CircuitBreakerManager::class,
    ],

    'listeners' => [
        WorkerStarting::class => [
            // Your listeners
        ],
    ],
];

// config/fuse.php
return [
    'default' => 'cache',

    'stores' => [
        'cache' => [
            'driver' => 'cache',
            'store' => 'redis',
            'prefix' => 'cb',
        ],
    ],

    'register_octane_reset_listener' => true,

    // ... other config
];

// .env
OCTANE_SERVER=roadrunner
OCTANE_WORKERS=4

FUSE_STORE=cache
FUSE_CACHE_STORE=redis
FUSE_REGISTER_OCTANE_RESET_LISTENER=true

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

## Next Steps

- **[Testing](testing.md)** - Test Octane-compatible code
- **[Advanced Usage](advanced-usage.md)** - Advanced Octane patterns
- **[Monitoring & Events](monitoring-and-events.md)** - Monitor Octane workers
