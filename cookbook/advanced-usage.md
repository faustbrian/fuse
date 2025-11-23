# Advanced Usage

Advanced patterns, custom implementations, and power-user techniques for Fuse circuit breakers.

## Custom Strategies

Create your own evaluation logic by implementing the `Strategy` contract.

### Strategy Interface

```php
namespace Cline\Fuse\Contracts;

use Cline\Fuse\ValueObjects\CircuitBreakerMetrics;
use Cline\Fuse\ValueObjects\CircuitBreakerConfiguration;

interface Strategy
{
    public function shouldOpen(
        CircuitBreakerMetrics $metrics,
        CircuitBreakerConfiguration $configuration
    ): bool;
}
```

### Time-of-Day Strategy

More sensitive during business hours:

```php
namespace App\CircuitBreaker\Strategies;

use Cline\Fuse\Contracts\Strategy;
use Cline\Fuse\ValueObjects\CircuitBreakerMetrics;
use Cline\Fuse\ValueObjects\CircuitBreakerConfiguration;

class TimeOfDayStrategy implements Strategy
{
    public function shouldOpen(
        CircuitBreakerMetrics $metrics,
        CircuitBreakerConfiguration $configuration
    ): bool {
        $hour = (int) date('H');
        $isBusinessHours = $hour >= 9 && $hour <= 17;

        // Lower threshold during business hours
        $threshold = $isBusinessHours
            ? $configuration->failureThreshold
            : $configuration->failureThreshold * 2;

        return $metrics->consecutiveFailures >= $threshold;
    }
}
```

### Weighted Strategy

Consider both rate and consecutive failures:

```php
class WeightedStrategy implements Strategy
{
    public function shouldOpen(
        CircuitBreakerMetrics $metrics,
        CircuitBreakerConfiguration $configuration
    ): bool {
        // Need minimum throughput
        if (!$metrics->hasSufficientThroughput($configuration->minimumThroughput)) {
            return false;
        }

        // Check consecutive failures (weight: 60%)
        $consecutiveScore = min(
            $metrics->consecutiveFailures / $configuration->failureThreshold,
            1.0
        ) * 0.6;

        // Check failure rate (weight: 40%)
        $rateScore = min(
            $metrics->failureRate() / $configuration->percentageThreshold,
            1.0
        ) * 0.4;

        // Open if combined score exceeds 0.8
        return ($consecutiveScore + $rateScore) >= 0.8;
    }
}
```

### Exponential Backoff Strategy

Increase threshold after each open:

```php
class ExponentialBackoffStrategy implements Strategy
{
    public function shouldOpen(
        CircuitBreakerMetrics $metrics,
        CircuitBreakerConfiguration $configuration
    ): bool {
        // Track how many times circuit has opened
        $openCount = Cache::get("circuit:{$configuration->name}:open_count", 0);

        // Increase threshold exponentially
        $adjustedThreshold = $configuration->failureThreshold * pow(2, $openCount);

        return $metrics->consecutiveFailures >= $adjustedThreshold;
    }
}
```

### Registering Custom Strategies

In a service provider:

```php
use Cline\Fuse\Facades\Fuse;

public function boot(): void
{
    Fuse::extend('time_of_day', fn () => new TimeOfDayStrategy());
    Fuse::extend('weighted', fn () => new WeightedStrategy());
    Fuse::extend('exponential_backoff', fn () => new ExponentialBackoffStrategy());
}
```

Usage:

```php
Fuse::make('api', strategyName: 'time_of_day')->call($callable);
Fuse::make('service', strategyName: 'weighted')->call($callable);
```

## Custom Storage Drivers

Implement custom storage backends by extending `CircuitBreakerStore`.

### Redis Driver with Custom Logic

```php
namespace App\CircuitBreaker\Stores;

use Cline\Fuse\Contracts\CircuitBreakerStore;
use Cline\Fuse\Enums\CircuitBreakerState;
use Cline\Fuse\ValueObjects\CircuitBreakerMetrics;
use Illuminate\Support\Facades\Redis;

class CustomRedisStore implements CircuitBreakerStore
{
    public function getState(string $name): CircuitBreakerState
    {
        $state = Redis::get("cb:{$name}:state");

        return match ($state) {
            'OPEN' => CircuitBreakerState::OPEN,
            'HALF_OPEN' => CircuitBreakerState::HALF_OPEN,
            default => CircuitBreakerState::CLOSED,
        };
    }

    public function recordSuccess(string $name): void
    {
        Redis::incr("cb:{$name}:successes");
        Redis::set("cb:{$name}:last_success", time());

        // Reset failure counter
        Redis::set("cb:{$name}:consecutive_failures", 0);
    }

    public function recordFailure(string $name): void
    {
        Redis::incr("cb:{$name}:failures");
        Redis::incr("cb:{$name}:consecutive_failures");
        Redis::set("cb:{$name}:last_failure", time());

        // Reset success counter
        Redis::set("cb:{$name}:consecutive_successes", 0);
    }

    // Implement other required methods...
}
```

### Distributed Store with Consensus

Use distributed consensus for multi-region deployments:

```php
class DistributedStore implements CircuitBreakerStore
{
    public function recordFailure(string $name): void
    {
        // Record in multiple regions
        $regions = ['us-east', 'us-west', 'eu-west'];

        foreach ($regions as $region) {
            $this->recordFailureInRegion($name, $region);
        }

        // Open circuit if majority agrees
        if ($this->getMajorityState($name)->isOpen()) {
            $this->transitionToOpen($name);
        }
    }

    private function getMajorityState(string $name): CircuitBreakerState
    {
        $states = collect($this->regions)->map(function ($region) use ($name) {
            return $this->getStateFromRegion($name, $region);
        });

        // Return most common state
        return $states->mode()->first();
    }
}
```

## Multi-Tenant Circuit Breakers

### Tenant-Specific Circuits

```php
class TenantCircuitBreakerService
{
    public function callForTenant($tenantId, callable $callback)
    {
        $circuitName = "api:tenant:{$tenantId}";

        return Fuse::make($circuitName)->call($callback);
    }

    public function getTenantHealth($tenantId): array
    {
        $breaker = Fuse::make("api:tenant:{$tenantId}");

        return [
            'state' => $breaker->getState()->name,
            'metrics' => $breaker->getMetrics(),
        ];
    }
}
```

### Shared Circuit with Per-Tenant Metrics

```php
public function callApi($tenantId, $endpoint)
{
    // Use shared circuit breaker
    return Fuse::make('shared-api')->call(function () use ($tenantId, $endpoint) {
        try {
            return Http::get($endpoint)->json();
        } catch (\Exception $e) {
            // Track per-tenant metrics separately
            $this->recordTenantFailure($tenantId);
            throw $e;
        }
    });
}

private function recordTenantFailure($tenantId): void
{
    Redis::incr("tenant:{$tenantId}:api:failures");
    Redis::set("tenant:{$tenantId}:api:last_failure", time());
}
```

## Cascading Circuit Breakers

### Primary and Fallback Services

```php
public function fetchData()
{
    // Try primary service
    try {
        return Fuse::make('primary-api')->call(function () {
            return Http::get('https://primary.example.com/data')->throw()->json();
        });
    } catch (CircuitBreakerOpenException $e) {
        // Primary circuit open, try secondary
        return Fuse::make('secondary-api')->call(function () {
            return Http::get('https://secondary.example.com/data')->throw()->json();
        });
    }
}
```

### Coordinated Circuits

```php
class CoordinatedCircuitBreaker
{
    public function call(string $service, callable $callback)
    {
        $breaker = Fuse::make($service);

        // Check dependent services
        if ($this->dependenciesAreDown($service)) {
            throw new CircuitBreakerOpenException(
                name: $service,
                fallbackValue: ['message' => 'Dependencies unavailable']
            );
        }

        return $breaker->call($callback);
    }

    private function dependenciesAreDown(string $service): bool
    {
        $dependencies = config("services.{$service}.dependencies", []);

        foreach ($dependencies as $dep) {
            if (Fuse::make($dep)->getState()->isOpen()) {
                return true;
            }
        }

        return false;
    }
}
```

## Dynamic Configuration

### Load from Database

```php
class DynamicCircuitBreakerService
{
    public function make(string $name)
    {
        $settings = DB::table('circuit_breaker_settings')
            ->where('name', $name)
            ->first();

        if (!$settings) {
            return Fuse::make($name);
        }

        $config = CircuitBreakerConfiguration::fromDefaults($name)
            ->withFailureThreshold($settings->failure_threshold)
            ->withSuccessThreshold($settings->success_threshold)
            ->withTimeout($settings->timeout);

        return Fuse::make($name, configuration: $config);
    }
}
```

### Feature Flag Integration

```php
public function makeCircuitBreaker(string $name)
{
    // Check if circuit breakers are enabled for this service
    if (!Feature::active("circuit-breaker:{$name}")) {
        // Return pass-through wrapper
        return new PassThroughCircuitBreaker();
    }

    return Fuse::make($name);
}
```

## Metrics Aggregation

### Aggregate Metrics Across Services

```php
class CircuitBreakerMetricsService
{
    public function getOverallHealth(): array
    {
        $circuits = ['api-1', 'api-2', 'api-3'];

        $metrics = collect($circuits)->map(function ($name) {
            $breaker = Fuse::make($name);

            return [
                'name' => $name,
                'state' => $breaker->getState()->name,
                'failure_rate' => $breaker->getMetrics()->failureRate(),
            ];
        });

        return [
            'healthy_count' => $metrics->where('state', 'CLOSED')->count(),
            'total_count' => $metrics->count(),
            'average_failure_rate' => $metrics->avg('failure_rate'),
            'circuits' => $metrics->all(),
        ];
    }
}
```

### Time-Series Metrics

```php
public function recordMetricsSnapshot(): void
{
    $circuits = ['payment-api', 'user-service', 'analytics-api'];

    foreach ($circuits as $name) {
        $breaker = Fuse::make($name);
        $metrics = $breaker->getMetrics();

        DB::table('circuit_breaker_snapshots')->insert([
            'name' => $name,
            'state' => $breaker->getState()->name,
            'failure_rate' => $metrics->failureRate(),
            'total_failures' => $metrics->totalFailures,
            'total_successes' => $metrics->totalSuccesses,
            'created_at' => now(),
        ]);
    }
}

// Schedule in Kernel.php
$schedule->call([app(CircuitBreakerMetricsService::class), 'recordMetricsSnapshot'])
    ->everyFiveMinutes();
```

## Circuit Breaker Pools

### Manage Groups of Circuits

```php
class CircuitBreakerPool
{
    private array $circuits = [];

    public function add(string $name, ?CircuitBreakerConfiguration $config = null): self
    {
        $this->circuits[$name] = Fuse::make($name, configuration: $config);
        return $this;
    }

    public function call(string $name, callable $callback)
    {
        if (!isset($this->circuits[$name])) {
            throw new \InvalidArgumentException("Circuit {$name} not in pool");
        }

        return $this->circuits[$name]->call($callback);
    }

    public function resetAll(): void
    {
        foreach ($this->circuits as $circuit) {
            $circuit->reset();
        }
    }

    public function getHealthStatus(): array
    {
        return collect($this->circuits)->map(function ($circuit, $name) {
            return [
                'name' => $name,
                'state' => $circuit->getState()->name,
                'healthy' => $circuit->getState()->isClosed(),
            ];
        })->all();
    }
}

// Usage
$pool = (new CircuitBreakerPool())
    ->add('api-1')
    ->add('api-2')
    ->add('api-3');

$result = $pool->call('api-1', $callable);
```

## Middleware Integration

### HTTP Middleware

```php
namespace App\Http\Middleware;

use Cline\Fuse\Facades\Fuse;
use Closure;

class CircuitBreakerMiddleware
{
    public function handle($request, Closure $next, $circuit)
    {
        $breaker = Fuse::make($circuit);

        if ($breaker->getState()->isOpen()) {
            return response()->json([
                'error' => 'Service temporarily unavailable',
                'circuit' => $circuit,
            ], 503);
        }

        return $next($request);
    }
}

// In routes/api.php
Route::get('/api/data', [ApiController::class, 'getData'])
    ->middleware('circuit_breaker:external-api');
```

### Job Middleware

```php
namespace App\Jobs\Middleware;

use Cline\Fuse\Facades\Fuse;
use Cline\Fuse\Exceptions\CircuitBreakerOpenException;

class CircuitBreakerJobMiddleware
{
    public function handle($job, $next)
    {
        $circuit = $job->circuit ?? 'default';

        try {
            Fuse::make($circuit)->call(function () use ($next, $job) {
                $next($job);
            });
        } catch (CircuitBreakerOpenException $e) {
            // Release job to retry later
            $job->release(300); // 5 minutes
        }
    }
}
```

## Testing Helpers

### Circuit Breaker Test Helpers

```php
trait CircuitBreakerTestHelpers
{
    protected function forceCircuitOpen(string $name): void
    {
        $breaker = Fuse::store('array')->make($name);

        for ($i = 0; $i < 5; $i++) {
            try {
                $breaker->call(fn () => throw new \Exception('Forced failure'));
            } catch (\Exception $e) {}
        }
    }

    protected function forceCircuitClosed(string $name): void
    {
        Fuse::store('array')->make($name)->reset();
    }

    protected function assertCircuitState(string $name, string $expectedState): void
    {
        $actual = Fuse::store('array')->make($name)->getState()->name;
        $this->assertEquals($expectedState, $actual);
    }
}
```

## Performance Optimization

### Lazy Circuit Breaker Creation

```php
class LazyCircuitBreaker
{
    private ?CircuitBreaker $breaker = null;

    public function __construct(
        private string $name,
        private ?CircuitBreakerConfiguration $config = null
    ) {}

    public function call(callable $callback)
    {
        if ($this->breaker === null) {
            $this->breaker = Fuse::make($this->name, configuration: $this->config);
        }

        return $this->breaker->call($callback);
    }
}
```

### Batch Operations

```php
public function batchApiCalls(array $ids): array
{
    $breaker = Fuse::make('batch-api');
    $results = [];

    // Check circuit once for entire batch
    if ($breaker->getState()->isOpen()) {
        return $this->getFallbackResults($ids);
    }

    foreach ($ids as $id) {
        try {
            $results[$id] = $breaker->call(fn () => $this->fetchItem($id));
        } catch (CircuitBreakerOpenException $e) {
            // Circuit opened mid-batch, use fallback for remaining
            $results = array_merge($results, $this->getFallbackResults(array_slice($ids, count($results))));
            break;
        }
    }

    return $results;
}
```

## Best Practices

1. **Custom Strategies**
   - Keep logic simple and fast
   - Test thoroughly
   - Document behavior

2. **Multi-Tenant**
   - Consider shared vs isolated circuits
   - Monitor per-tenant metrics
   - Balance isolation with resource usage

3. **Cascading**
   - Prevent cascading failures
   - Set different thresholds for fallbacks
   - Monitor dependency chains

4. **Dynamic Configuration**
   - Cache configuration when possible
   - Validate changes before applying
   - Provide rollback mechanism

5. **Performance**
   - Minimize overhead in hot paths
   - Batch when possible
   - Use appropriate storage driver

## Next Steps

- **[Octane Support](octane-support.md)** - Long-running process considerations
- **[Testing](testing.md)** - Test advanced patterns
- **[Use Cases](use-cases.md)** - Real-world advanced examples
