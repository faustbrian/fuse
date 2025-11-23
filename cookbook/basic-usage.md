# Basic Usage

This guide covers all the core operations you'll use daily with Fuse circuit breakers.

## Creating Circuit Breakers

### Simple Circuit Breaker

The simplest way to create a circuit breaker is with just a name:

```php
use Cline\Fuse\Facades\Fuse;

$result = Fuse::make('external-api')->call(function () {
    return Http::get('https://api.example.com/data')->json();
});
```

This creates a circuit breaker with default settings:
- 5 consecutive failures to open
- 2 consecutive successes to close
- 60-second timeout when open
- Consecutive failures strategy

### Circuit Breaker with Custom Configuration

Create a circuit breaker with custom thresholds:

```php
use Cline\Fuse\ValueObjects\CircuitBreakerConfiguration;

$config = CircuitBreakerConfiguration::fromDefaults('payment-api')
    ->withFailureThreshold(10)
    ->withSuccessThreshold(3)
    ->withTimeout(120);

$result = Fuse::make('payment-api', configuration: $config)
    ->call($callable);
```

### Using Different Strategies

Specify an evaluation strategy when creating the circuit breaker:

```php
// Consecutive failures (default)
Fuse::make('api', strategyName: 'consecutive_failures')
    ->call($callable);

// Percentage-based failures
Fuse::make('api', strategyName: 'percentage_failures')
    ->call($callable);

// Rolling window
Fuse::make('api', strategyName: 'rolling_window')
    ->call($callable);
```

### Using Different Storage Drivers

Switch between storage drivers:

```php
// Use cache driver (default in production)
Fuse::store('cache')->make('api')->call($callable);

// Use database driver for audit trail
Fuse::store('database')->make('api')->call($callable);

// Use array driver for testing
Fuse::store('array')->make('api')->call($callable);
```

## Executing Protected Operations

### Basic Call

The `call()` method executes your operation under circuit breaker protection:

```php
$result = Fuse::make('external-api')->call(function () {
    return Http::timeout(5)->get('https://api.example.com/data')->json();
});
```

### Call with Parameters

Pass data to your closure:

```php
$userId = 123;
$result = Fuse::make('user-api')->call(function () use ($userId) {
    return Http::get("https://api.example.com/users/{$userId}")->json();
});
```

### Call with Return Type

Circuit breakers pass through your return values:

```php
/** @return array */
$userData = Fuse::make('user-api')->call(function (): array {
    return Http::get('https://api.example.com/user')->json();
});
```

### Multiple Calls

Each call is independently tracked:

```php
$breaker = Fuse::make('api');

// First call
$users = $breaker->call(fn () => Http::get('/users')->json());

// Second call
$posts = $breaker->call(fn () => Http::get('/posts')->json());

// Third call
$comments = $breaker->call(fn () => Http::get('/comments')->json());
```

## Handling Failures

### Try-Catch Pattern

Handle circuit breaker exceptions:

```php
use Cline\Fuse\Exceptions\CircuitBreakerOpenException;

try {
    $result = Fuse::make('api')->call($callable);
} catch (CircuitBreakerOpenException $e) {
    Log::warning("Circuit breaker '{$e->name}' is open");

    // Return cached data
    return Cache::get('api-data');
}
```

### Checking for Fallbacks

Circuit breaker exceptions can include fallback values:

```php
try {
    $result = Fuse::make('api')->call($callable);
} catch (CircuitBreakerOpenException $e) {
    if ($e->hasFallback()) {
        // Use the configured fallback
        return $e->fallbackValue;
    }

    // No fallback configured
    throw $e;
}
```

### Nested Try-Catch

Handle both circuit breaker and operation exceptions:

```php
try {
    $result = Fuse::make('api')->call(function () {
        return Http::get('https://api.example.com/data')
            ->throw()
            ->json();
    });
} catch (CircuitBreakerOpenException $e) {
    // Circuit is open
    return $this->getCachedData();
} catch (\Illuminate\Http\Client\ConnectionException $e) {
    // Network error (will be recorded as failure)
    Log::error("API connection failed: {$e->getMessage()}");
    throw $e;
}
```

## Checking Circuit State

### Get Current State

```php
$breaker = Fuse::make('external-api');
$state = $breaker->getState();

// Check state
if ($state->isClosed()) {
    echo "Circuit is closed - operating normally";
}

if ($state->isOpen()) {
    echo "Circuit is open - requests blocked";
}

if ($state->isHalfOpen()) {
    echo "Circuit is half-open - testing recovery";
}
```

### State Enum Values

```php
use Cline\Fuse\Enums\CircuitBreakerState;

$state = $breaker->getState();

match ($state) {
    CircuitBreakerState::CLOSED => 'Normal operation',
    CircuitBreakerState::OPEN => 'Service unavailable',
    CircuitBreakerState::HALF_OPEN => 'Testing recovery',
};
```

### Conditional Logic Based on State

```php
$breaker = Fuse::make('optional-feature');

if ($breaker->getState()->isClosed()) {
    // Only use the feature if circuit is closed
    $data = $breaker->call($callable);
} else {
    // Skip the feature
    $data = null;
}
```

## Viewing Metrics

### Get Circuit Breaker Metrics

```php
$breaker = Fuse::make('external-api');
$metrics = $breaker->getMetrics();

// Access metric properties
echo "Total failures: {$metrics->totalFailures}";
echo "Total successes: {$metrics->totalSuccesses}";
echo "Consecutive failures: {$metrics->consecutiveFailures}";
echo "Consecutive successes: {$metrics->consecutiveSuccesses}";
echo "Last failure time: {$metrics->lastFailureTime}";
echo "Last success time: {$metrics->lastSuccessTime}";
```

### Calculate Failure Rate

```php
$metrics = $breaker->getMetrics();
$failureRate = $metrics->failureRate();

if ($failureRate > 50) {
    Log::warning("High failure rate detected: {$failureRate}%");
}
```

### Display Metrics in Admin Panel

```php
public function showCircuitBreakerStatus()
{
    $circuits = ['external-api', 'payment-service', 'email-service'];
    $status = [];

    foreach ($circuits as $name) {
        $breaker = Fuse::make($name);
        $metrics = $breaker->getMetrics();

        $status[] = [
            'name' => $name,
            'state' => $breaker->getState()->name,
            'failure_rate' => $metrics->failureRate(),
            'total_failures' => $metrics->totalFailures,
            'total_successes' => $metrics->totalSuccesses,
        ];
    }

    return view('admin.circuit-breakers', compact('status'));
}
```

## Manual Operations

### Reset a Circuit Breaker

Manually reset a circuit breaker to closed state:

```php
$breaker = Fuse::make('external-api');
$breaker->reset();

// Circuit is now closed with all counters reset
$state = $breaker->getState(); // CircuitBreakerState::CLOSED
```

### Force State Transitions

Circuit breakers automatically transition states based on failures and successes, but you can also trigger transitions manually for testing or administrative purposes:

```php
// These methods are on the store, not the breaker
$store = Fuse::store('cache');

// Force open
$store->transitionToOpen('external-api');

// Force closed
$store->transitionToClosed('external-api');

// Force half-open
$store->transitionToHalfOpen('external-api');
```

## Reusing Circuit Breakers

### Store Breaker Instance

You can reuse the same circuit breaker instance across multiple calls:

```php
class UserApiService
{
    private CircuitBreaker $breaker;

    public function __construct()
    {
        $this->breaker = Fuse::make('user-api');
    }

    public function getUser($id)
    {
        return $this->breaker->call(fn () => $this->fetchUser($id));
    }

    public function updateUser($id, $data)
    {
        return $this->breaker->call(fn () => $this->patchUser($id, $data));
    }

    public function deleteUser($id)
    {
        return $this->breaker->call(fn () => $this->removeUser($id));
    }
}
```

### Dependency Injection

Inject circuit breakers via service container:

```php
class PaymentService
{
    public function __construct(
        private CircuitBreakerManager $fuse
    ) {}

    public function processPayment($amount)
    {
        return $this->fuse->make('payment-gateway')->call(function () use ($amount) {
            return $this->chargeCard($amount);
        });
    }
}
```

## Working with Multiple Stores

### Define Multiple Stores

In `config/fuse.php`:

```php
'stores' => [
    'redis' => [
        'driver' => 'cache',
        'store' => 'redis',
        'prefix' => 'cb',
    ],
    'database' => [
        'driver' => 'database',
        'connection' => 'mysql',
    ],
    'critical' => [
        'driver' => 'database',
        'connection' => 'mysql',
    ],
],
```

### Use Specific Stores

```php
// Critical operations use database for audit trail
Fuse::store('critical')
    ->make('payment-processing')
    ->call($callable);

// Regular operations use Redis cache
Fuse::store('redis')
    ->make('user-service')
    ->call($callable);

// Testing uses array store
Fuse::store('array')
    ->make('test-service')
    ->call($callable);
```

## Configuration Patterns

### Per-Environment Configuration

```php
// Strict in production
if (app()->environment('production')) {
    $config = CircuitBreakerConfiguration::fromDefaults('api')
        ->withFailureThreshold(3)
        ->withTimeout(120);
}

// Lenient in development
if (app()->environment('local')) {
    $config = CircuitBreakerConfiguration::fromDefaults('api')
        ->withFailureThreshold(20)
        ->withTimeout(10);
}

$result = Fuse::make('api', configuration: $config)->call($callable);
```

### Service-Specific Configuration

```php
// Payment service: very strict
$paymentConfig = CircuitBreakerConfiguration::fromDefaults('payment')
    ->withFailureThreshold(2)
    ->withSuccessThreshold(5)
    ->withTimeout(180);

// Analytics service: more tolerant
$analyticsConfig = CircuitBreakerConfiguration::fromDefaults('analytics')
    ->withFailureThreshold(10)
    ->withSuccessThreshold(2)
    ->withTimeout(30);

// Email service: very tolerant (not critical)
$emailConfig = CircuitBreakerConfiguration::fromDefaults('email')
    ->withFailureThreshold(20)
    ->withSuccessThreshold(1)
    ->withTimeout(60);
```

### Configuration from Database

```php
public function makeCircuitBreaker(string $serviceName)
{
    $settings = DB::table('circuit_breaker_settings')
        ->where('service', $serviceName)
        ->first();

    $config = CircuitBreakerConfiguration::fromDefaults($serviceName)
        ->withFailureThreshold($settings->failure_threshold)
        ->withSuccessThreshold($settings->success_threshold)
        ->withTimeout($settings->timeout);

    return Fuse::make($serviceName, configuration: $config);
}
```

## Common Use Cases

### Protecting HTTP Client Calls

```php
public function fetchExternalData($endpoint)
{
    return Fuse::make('external-api')->call(function () use ($endpoint) {
        return Http::timeout(10)
            ->retry(2, 100)
            ->get($endpoint)
            ->throw()
            ->json();
    });
}
```

### Protecting Database Operations

```php
public function runExpensiveQuery()
{
    return Fuse::make('reporting-db')->call(function () {
        return DB::connection('reporting')
            ->table('large_table')
            ->where('date', '>', now()->subMonths(3))
            ->get();
    });
}
```

### Protecting Third-Party SDK Calls

```php
use Stripe\StripeClient;

public function createCharge($amount)
{
    return Fuse::make('stripe-api')->call(function () use ($amount) {
        $stripe = new StripeClient(config('services.stripe.secret'));

        return $stripe->charges->create([
            'amount' => $amount,
            'currency' => 'usd',
        ]);
    });
}
```

### Background Jobs

```php
class ProcessWebhook implements ShouldQueue
{
    public function handle()
    {
        try {
            Fuse::make('webhook-processor')->call(function () {
                $this->processWebhookData();
            });
        } catch (CircuitBreakerOpenException $e) {
            // Retry job later
            $this->release(300); // 5 minutes
        }
    }
}
```

## Best Practices

1. **Naming Conventions**
   - Use descriptive, consistent names
   - Group related services: `stripe-charges`, `stripe-refunds`
   - Avoid generic names: `api`, `service`, `external`

2. **Error Handling**
   - Always catch `CircuitBreakerOpenException`
   - Provide meaningful fallbacks
   - Log circuit breaker events

3. **Configuration**
   - Start with defaults
   - Tune based on real-world metrics
   - More critical = stricter thresholds

4. **Testing**
   - Use array driver in tests
   - Test both success and failure paths
   - Verify state transitions

5. **Monitoring**
   - Track circuit states in production
   - Alert on circuit opens
   - Review metrics regularly

## Next Steps

- **[Storage Drivers](storage-drivers.md)** - Deep dive into array, cache, and database drivers
- **[Strategies](strategies.md)** - Learn about different evaluation strategies
- **[Configuration](configuration.md)** - Comprehensive configuration guide
- **[Fallback Handlers](fallback-handlers.md)** - Advanced graceful degradation
- **[Monitoring & Events](monitoring-and-events.md)** - Integrate with monitoring systems
- **[Testing](testing.md)** - How to test circuit breaker protected code
