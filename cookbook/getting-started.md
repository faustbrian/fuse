# Getting Started

Welcome to Fuse, a highly configurable circuit breaker package for Laravel applications. This guide will help you install, configure, and create your first circuit breaker to protect your application from cascading failures.

## What is a Circuit Breaker?

A circuit breaker is a design pattern that prevents your application from repeatedly trying to execute an operation that's likely to fail. Like an electrical circuit breaker that protects your home from electrical overload, a software circuit breaker protects your application from cascading failures when external services or operations fail.

### Why Use Circuit Breakers?

- **Prevent Cascading Failures** - Stop failures from spreading across your system
- **Fail Fast** - Immediately reject requests when a service is known to be down
- **Allow Time to Recover** - Give failing services breathing room to recover
- **Graceful Degradation** - Provide fallback responses instead of hard failures
- **Monitor Service Health** - Track failure rates and service availability

## Installation

Install Fuse via Composer:

```bash
composer require cline/fuse
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=fuse-config
```

This creates `config/fuse.php` with comprehensive configuration options. The default settings work well for most applications, but you can customize them to suit your needs.

### Choosing a Storage Driver

Fuse supports three storage drivers for tracking circuit breaker state:

**Array Driver** (in-memory)
- Best for: Testing, development, single-server applications
- State persists only during the current request
- No additional setup required
- Not suitable for multi-server deployments

**Cache Driver** (recommended for production)
- Best for: Production environments with Redis or Memcached
- State shared across all application instances
- Excellent performance
- Requires cache configuration

**Database Driver**
- Best for: When you need persistent audit trails
- State survives application restarts
- Full history of state transitions
- Requires database migrations

To set your default driver in `.env`:

```env
FUSE_STORE=cache
```

## Database Setup

If using the database driver, publish and run the migrations:

```bash
php artisan vendor:publish --tag=fuse-migrations
php artisan migrate
```

This creates two tables:

**circuit_breakers** - Stores circuit breaker state and metrics
| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint/ulid/uuid | Primary key (configurable) |
| `name` | string | Unique circuit breaker name |
| `state` | string | Current state (CLOSED, OPEN, HALF_OPEN) |
| `failure_count` | integer | Number of failures recorded |
| `success_count` | integer | Number of successes recorded |
| `last_failure_at` | timestamp | When the last failure occurred |
| `opened_at` | timestamp | When the circuit opened |
| `closed_at` | timestamp | When the circuit closed |

**circuit_breaker_events** - Audit trail of state transitions
| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint/ulid/uuid | Primary key |
| `circuit_breaker_id` | bigint/ulid/uuid | Foreign key to circuit_breakers |
| `event` | string | Event type (opened, closed, half_opened) |
| `metadata` | json | Additional event data |
| `created_at` | timestamp | When the event occurred |

## Your First Circuit Breaker

Let's protect an external API call with a circuit breaker. When the API starts failing, the circuit breaker will automatically open and reject further requests until the service recovers.

### 1. Wrap Your Risky Operation

In your service or controller:

```php
use Cline\Fuse\Facades\Fuse;
use Illuminate\Support\Facades\Http;

class UserService
{
    public function fetchUserData($userId)
    {
        return Fuse::make('external-api')->call(function () use ($userId) {
            return Http::timeout(5)
                ->get("https://api.example.com/users/{$userId}")
                ->throw()
                ->json();
        });
    }
}
```

That's it! Fuse will now:
1. Track successes and failures automatically
2. Open the circuit after 5 consecutive failures (default)
3. Reject requests for 60 seconds when open
4. Test recovery in half-open state
5. Close when the service recovers

### 2. Handle Circuit Breaker Exceptions

When a circuit breaker is open, it throws a `CircuitBreakerOpenException`:

```php
use Cline\Fuse\Exceptions\CircuitBreakerOpenException;

try {
    $userData = $this->userService->fetchUserData($userId);
} catch (CircuitBreakerOpenException $e) {
    // Circuit is open - service is unavailable
    Log::warning("User API circuit breaker is open");

    // Return cached data or a default response
    return Cache::get("user:{$userId}") ?? ['status' => 'unavailable'];
}
```

### 3. Use Fallback Handlers (Optional)

Instead of catching exceptions, you can configure fallback handlers that automatically provide alternative responses:

```php
// In config/fuse.php
'fallbacks' => [
    'enabled' => true,
    'handlers' => [
        'external-api' => fn () => [
            'status' => 'unavailable',
            'message' => 'Service temporarily unavailable',
        ],
    ],
],

// The exception now contains the fallback value
try {
    $userData = Fuse::make('external-api')->call($callable);
} catch (CircuitBreakerOpenException $e) {
    if ($e->hasFallback()) {
        return $e->fallbackValue; // Returns the configured fallback
    }
}
```

## Understanding Circuit Breaker States

A circuit breaker has three states:

### CLOSED (Normal Operation)
- All requests pass through to the protected service
- Successes and failures are tracked
- Circuit opens when failure threshold is reached

```php
$breaker = Fuse::make('api');
$breaker->getState(); // CircuitBreakerState::CLOSED
```

### OPEN (Failing Fast)
- Requests are immediately rejected without calling the service
- Prevents load on the failing service
- Remains open for the configured timeout period
- Transitions to HALF_OPEN when timeout expires

```php
$breaker->getState(); // CircuitBreakerState::OPEN
```

### HALF_OPEN (Testing Recovery)
- Allows limited requests through to test if service recovered
- If requests succeed, circuit closes
- If requests fail, circuit reopens

```php
$breaker->getState(); // CircuitBreakerState::HALF_OPEN
```

## Basic Configuration

You can customize circuit breaker behavior when creating it:

```php
use Cline\Fuse\ValueObjects\CircuitBreakerConfiguration;

$config = CircuitBreakerConfiguration::fromDefaults('payment-api')
    ->withFailureThreshold(10)      // Open after 10 consecutive failures
    ->withSuccessThreshold(3)       // Close after 3 consecutive successes
    ->withTimeout(120);             // Stay open for 2 minutes

$result = Fuse::make('payment-api', configuration: $config)
    ->call($callable);
```

## Common Patterns

### Protecting External API Calls

```php
public function getWeather($location)
{
    return Fuse::make('weather-api')->call(function () use ($location) {
        return Http::get('https://api.weather.com/current', [
            'location' => $location,
        ])->json();
    });
}
```

### Protecting Database Queries

```php
public function getAnalytics()
{
    return Fuse::make('analytics-db')->call(function () {
        return DB::connection('analytics')
            ->table('events')
            ->where('created_at', '>', now()->subDays(30))
            ->count();
    });
}
```

### Protecting Microservice Calls

```php
public function processPayment($orderId)
{
    return Fuse::make('payment-service')->call(function () use ($orderId) {
        return Http::post('http://payment-service/api/charge', [
            'order_id' => $orderId,
        ])->throw()->json();
    });
}
```

## Monitoring Circuit Breaker Health

### Check Circuit State

```php
$breaker = Fuse::make('external-api');

// Get current state
$state = $breaker->getState();

if ($state->isOpen()) {
    Log::warning('Circuit breaker is open');
}
```

### View Metrics

```php
$metrics = $breaker->getMetrics();

echo "Total failures: {$metrics->totalFailures}";
echo "Consecutive failures: {$metrics->consecutiveFailures}";
echo "Failure rate: {$metrics->failureRate()}%";
echo "Last failure: {$metrics->lastFailureTime}";
```

### Manual Reset

If you need to manually reset a circuit breaker:

```php
$breaker->reset();
```

## Best Practices

1. **Naming Conventions**
   - Use descriptive names: `payment-api`, `user-service`, `analytics-db`
   - Group related services: `stripe-api`, `stripe-webhooks`
   - Be consistent across your application

2. **Threshold Tuning**
   - Start with defaults (5 failures, 60-second timeout)
   - Monitor and adjust based on real-world behavior
   - More critical services may need lower thresholds

3. **Fallback Strategies**
   - Always provide graceful degradation
   - Use cached data when available
   - Return user-friendly error messages
   - Log circuit breaker events for monitoring

4. **Testing**
   - Use the array driver for unit tests
   - Test both success and failure scenarios
   - Verify fallback behavior

5. **Monitoring**
   - Listen for circuit breaker events
   - Alert when circuits open
   - Track recovery patterns

## Environment-Specific Configuration

Configure different settings for different environments:

```env
# Production - sensitive to failures
FUSE_FAILURE_THRESHOLD=5
FUSE_TIMEOUT=60
FUSE_STORE=cache

# Staging - more tolerant
FUSE_FAILURE_THRESHOLD=10
FUSE_TIMEOUT=30
FUSE_STORE=database

# Local - disabled or very tolerant
FUSE_FAILURE_THRESHOLD=100
FUSE_TIMEOUT=10
FUSE_STORE=array
```

## Next Steps

Now that you have Fuse installed and understand the basics, explore more advanced features:

- **[Basic Usage](basic-usage.md)** - Learn all core operations and methods
- **[Storage Drivers](storage-drivers.md)** - Deep dive into array, cache, and database drivers
- **[Strategies](strategies.md)** - Consecutive failures, percentage-based, and rolling window strategies
- **[Configuration](configuration.md)** - Comprehensive guide to all configuration options
- **[Fallback Handlers](fallback-handlers.md)** - Advanced graceful degradation patterns
- **[Monitoring & Events](monitoring-and-events.md)** - Integrate with monitoring systems
- **[Exception Handling](exception-handling.md)** - Control which exceptions trigger failures
- **[Testing](testing.md)** - How to test code using circuit breakers
- **[Use Cases](use-cases.md)** - Real-world examples and patterns
