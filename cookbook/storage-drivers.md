# Storage Drivers

Fuse supports three storage drivers for persisting circuit breaker state: Array (in-memory), Cache, and Database. Each driver has different characteristics and use cases.

## Overview

| Driver | Persistence | Shared State | Performance | Use Case |
|--------|-------------|--------------|-------------|----------|
| Array | Request-only | No | Fastest | Testing, development |
| Cache | Yes (volatile) | Yes | Very fast | Production (recommended) |
| Database | Yes (permanent) | Yes | Fast | Audit trails, compliance |

## Array Driver

The array driver stores circuit breaker state in memory for the duration of the current request. State is not shared across requests or server instances.

### Configuration

```php
// In config/fuse.php
'stores' => [
    'array' => [
        'driver' => 'array',
    ],
],
```

### When to Use

**Perfect for:**
- Unit testing
- Integration testing
- Development environments
- Single-request operations
- Prototype/proof-of-concept work

**Not suitable for:**
- Production environments
- Multi-server deployments
- Long-running operations
- State that needs to persist

### Usage

```php
use Cline\Fuse\Facades\Fuse;

// Use array driver explicitly
$result = Fuse::store('array')
    ->make('test-service')
    ->call($callable);

// State exists only during this request
$state = Fuse::store('array')
    ->make('test-service')
    ->getState();
```

### Testing Example

```php
use Cline\Fuse\Facades\Fuse;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    public function test_circuit_breaker_opens_after_failures()
    {
        // Array driver perfect for testing
        $breaker = Fuse::store('array')->make('test-service');

        // Simulate 5 failures
        for ($i = 0; $i < 5; $i++) {
            try {
                $breaker->call(fn () => throw new \Exception('Fail'));
            } catch (\Exception $e) {
                // Expected
            }
        }

        // Circuit should be open
        $this->assertTrue($breaker->getState()->isOpen());
    }
}
```

### Characteristics

**Pros:**
- Zero configuration
- Fastest performance
- No external dependencies
- Perfect for testing
- Clean slate every request

**Cons:**
- No persistence
- Not shared across servers
- Not shared across requests
- Cannot track long-term patterns

## Cache Driver

The cache driver leverages Laravel's cache system to store circuit breaker state. This is the recommended driver for production environments.

### Configuration

```php
// In config/fuse.php
'stores' => [
    'cache' => [
        'driver' => 'cache',
        'store' => env('FUSE_CACHE_STORE'), // null = default cache
        'prefix' => env('FUSE_CACHE_PREFIX', 'circuit_breaker'),
    ],
],
```

### Environment Variables

```env
# Use default cache
FUSE_STORE=cache

# Use specific cache store
FUSE_CACHE_STORE=redis

# Customize key prefix
FUSE_CACHE_PREFIX=cb
```

### When to Use

**Perfect for:**
- Production environments
- Multi-server deployments
- High-traffic applications
- Shared state across instances
- Fast state transitions

**Not suitable for:**
- When you need permanent audit trails
- When cache is unreliable
- When compliance requires persistence
- When cache might be cleared frequently

### Usage

```php
// Use default cache store
$result = Fuse::store('cache')
    ->make('external-api')
    ->call($callable);

// State is shared across all servers
$breaker = Fuse::store('cache')->make('external-api');
$state = $breaker->getState();
```

### Redis Configuration

For best performance, use Redis:

```php
// In config/cache.php
'stores' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'cache',
        'lock_connection' => 'default',
    ],
],

// In .env
CACHE_DRIVER=redis
FUSE_CACHE_STORE=redis
```

### Multiple Cache Stores

Create separate stores for different purposes:

```php
// In config/fuse.php
'stores' => [
    // Regular services use Redis
    'redis' => [
        'driver' => 'cache',
        'store' => 'redis',
        'prefix' => 'cb',
    ],

    // Critical services use dedicated Redis instance
    'critical' => [
        'driver' => 'cache',
        'store' => 'redis-critical',
        'prefix' => 'critical_cb',
    ],
],

// Usage
Fuse::store('redis')->make('user-api')->call($callable);
Fuse::store('critical')->make('payment-api')->call($callable);
```

### Cache Key Structure

Circuit breaker data is stored with keys like:

```
circuit_breaker:external-api:state
circuit_breaker:external-api:metrics
circuit_breaker:external-api:failures
circuit_breaker:external-api:successes
```

The prefix prevents collisions with other cached data.

### Characteristics

**Pros:**
- Excellent performance
- Shared across all servers
- Survives deployments
- Native Redis/Memcached support
- Scales horizontally

**Cons:**
- Volatile (cleared on cache flush)
- No audit trail
- Depends on cache reliability
- Memory-based (costs more at scale)

## Database Driver

The database driver persists circuit breaker state and events to your database. This provides a permanent audit trail of all state transitions.

### Configuration

```php
// In config/fuse.php
'stores' => [
    'database' => [
        'driver' => 'database',
        'connection' => env('DB_CONNECTION'),
    ],
],
```

### Migration

Publish and run migrations:

```bash
php artisan vendor:publish --tag=fuse-migrations
php artisan migrate
```

This creates two tables:

**circuit_breakers** - Stores current state
```sql
CREATE TABLE circuit_breakers (
    id BIGINT PRIMARY KEY,
    name VARCHAR(255) UNIQUE NOT NULL,
    state VARCHAR(20) NOT NULL,
    failure_count INT NOT NULL DEFAULT 0,
    success_count INT NOT NULL DEFAULT 0,
    last_failure_at TIMESTAMP NULL,
    opened_at TIMESTAMP NULL,
    closed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

**circuit_breaker_events** - Audit trail
```sql
CREATE TABLE circuit_breaker_events (
    id BIGINT PRIMARY KEY,
    circuit_breaker_id BIGINT NOT NULL,
    event VARCHAR(50) NOT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NULL
);
```

### When to Use

**Perfect for:**
- Audit trail requirements
- Compliance needs
- Historical analysis
- Debugging production issues
- Long-term metrics

**Not suitable for:**
- Extremely high-traffic systems
- When database is a single point of failure
- When write latency is critical
- When database load is already high

### Usage

```php
// Use database driver
$result = Fuse::store('database')
    ->make('external-api')
    ->call($callable);

// Query historical state
$breaker = CircuitBreaker::where('name', 'external-api')->first();
echo "Circuit opened at: {$breaker->opened_at}";

// View event history
$events = $breaker->events()->latest()->get();
```

### Custom Connection

Use a separate database for circuit breakers:

```php
// In config/fuse.php
'stores' => [
    'database' => [
        'driver' => 'database',
        'connection' => 'circuit_breakers',
    ],
],

// In config/database.php
'connections' => [
    'circuit_breakers' => [
        'driver' => 'mysql',
        'host' => env('CB_DB_HOST', '127.0.0.1'),
        'database' => env('CB_DB_DATABASE', 'circuit_breakers'),
        // ... other settings
    ],
],
```

### Custom Models

Extend the Eloquent models:

```php
namespace App\Models;

use Cline\Fuse\Database\CircuitBreaker as BaseCircuitBreaker;

class CircuitBreaker extends BaseCircuitBreaker
{
    // Add custom methods
    public function isHealthy(): bool
    {
        return $this->state === 'CLOSED'
            && $this->failure_count < 3;
    }

    // Add relationships
    public function alerts()
    {
        return $this->hasMany(CircuitBreakerAlert::class);
    }
}

// Register in config/fuse.php
'models' => [
    'circuit_breaker' => \App\Models\CircuitBreaker::class,
],
```

### Querying Circuit Breakers

```php
use Cline\Fuse\Database\CircuitBreaker;

// Find open circuits
$openCircuits = CircuitBreaker::where('state', 'OPEN')->get();

// Find circuits with high failure rates
$failingCircuits = CircuitBreaker::where('failure_count', '>', 10)
    ->where('created_at', '>', now()->subHour())
    ->get();

// Get circuits that opened today
$todayOpened = CircuitBreaker::whereNotNull('opened_at')
    ->whereDate('opened_at', today())
    ->get();
```

### Event History

```php
use Cline\Fuse\Database\CircuitBreakerEvent;

// Get recent events for a circuit
$events = CircuitBreakerEvent::whereHas('circuitBreaker', function ($query) {
    $query->where('name', 'external-api');
})->latest()->take(50)->get();

// Count state transitions today
$transitions = CircuitBreakerEvent::whereDate('created_at', today())
    ->where('event', 'opened')
    ->count();
```

### Characteristics

**Pros:**
- Permanent storage
- Full audit trail
- Historical analysis
- Compliance-friendly
- Queryable with Eloquent

**Cons:**
- Slower than cache
- Database write overhead
- Requires migrations
- More complex setup
- Potential single point of failure

## Choosing the Right Driver

### Decision Tree

```
Need permanent audit trail?
├─ Yes → Database
└─ No → Need shared state across servers?
    ├─ Yes → Cache (Redis recommended)
    └─ No → Array (testing only)
```

### By Environment

**Local Development**
```env
FUSE_STORE=array
```
- Fast iteration
- No setup needed
- Clean slate each request

**Testing**
```env
FUSE_STORE=array
```
- Isolated tests
- Predictable state
- No cleanup needed

**Staging**
```env
FUSE_STORE=cache
FUSE_CACHE_STORE=redis
```
- Production-like behavior
- Shared state
- Good performance

**Production**
```env
FUSE_STORE=cache
FUSE_CACHE_STORE=redis
```
- Best performance
- Scales horizontally
- Shared state

**Production with Compliance**
```env
FUSE_STORE=database
```
- Audit requirements
- Historical analysis
- Permanent records

### Hybrid Approach

Use different drivers for different services:

```php
// Critical financial transactions: database for audit trail
Fuse::store('database')
    ->make('payment-processing')
    ->call($callable);

// Regular API calls: cache for performance
Fuse::store('cache')
    ->make('user-service')
    ->call($callable);

// Internal services: array for simplicity
Fuse::store('array')
    ->make('internal-tool')
    ->call($callable);
```

## Performance Comparison

### Benchmark Results

Based on 10,000 circuit breaker calls:

| Driver | Avg Time | Memory | Throughput |
|--------|----------|--------|------------|
| Array | 0.05ms | 1MB | 20,000/sec |
| Cache (Redis) | 0.8ms | 5MB | 1,250/sec |
| Database (MySQL) | 3.2ms | 2MB | 312/sec |

### Optimization Tips

**Array Driver:**
- Already optimal
- No tuning needed

**Cache Driver:**
- Use Redis over Memcached
- Use persistent connections
- Enable Redis pipelining
- Consider dedicated Redis instance

**Database Driver:**
- Add appropriate indexes
- Use read replicas for queries
- Consider connection pooling
- Batch event writes
- Prune old events regularly

## Migration Between Drivers

### From Array to Cache

No migration needed - array state is temporary.

```php
// Simply change configuration
'default' => 'cache',
```

### From Cache to Database

State is lost during transition:

```php
// Step 1: Switch to database
'default' => 'database',

// Step 2: All circuits start fresh
// They will rebuild state naturally
```

### From Database to Cache

Export critical state if needed:

```php
// Optional: Reset all circuits before switching
CircuitBreaker::query()->update([
    'state' => 'CLOSED',
    'failure_count' => 0,
    'success_count' => 0,
]);

// Switch driver
'default' => 'cache',
```

## Next Steps

- **[Strategies](strategies.md)** - Learn about evaluation strategies
- **[Configuration](configuration.md)** - Detailed configuration options
- **[Advanced Usage](advanced-usage.md)** - Custom drivers and extensions
- **[Testing](testing.md)** - Testing with different drivers
