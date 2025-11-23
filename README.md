# Fuse

**Highly configurable circuit breaker for Laravel applications**

[![Latest Version on Packagist](https://img.shields.io/packagist/v/cline/fuse.svg?style=flat-square)](https://packagist.org/packages/cline/fuse)
[![GitHub Tests Action Status](https://img.shields.io/github/workflow/status/cline/fuse/run-tests?label=tests)](https://github.com/cline/fuse/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/cline/fuse.svg?style=flat-square)](https://packagist.org/packages/cline/fuse)

Fuse provides fault tolerance and resilience for your Laravel applications through the circuit breaker pattern. Automatically detect failing services, prevent cascading failures, and allow systems time to recover.

## Features

- 🔥 **Multiple Storage Drivers** - Array (memory), Cache, Database
- 🎯 **Multiple Evaluation Strategies** - Consecutive failures, percentage-based, rolling window
- ⚙️ **Highly Configurable** - Fine-tune thresholds, timeouts, and behavior per circuit breaker
- 📊 **Metrics & Monitoring** - Track success/failure rates, state transitions
- 🎭 **Fallback Support** - Graceful degradation with custom fallback handlers
- 📢 **Event-Driven** - Listen for circuit breaker state changes
- 🚀 **Laravel Octane Compatible** - Full support for long-running processes
- 💪 **Type-Safe** - Strict types and comprehensive PHPStan coverage

## Installation

```bash
composer require cline/fuse
```

### Publish Configuration

```bash
php artisan vendor:publish --tag=fuse-config
```

### Publish Migrations (Database Driver Only)

```bash
php artisan vendor:publish --tag=fuse-migrations
php artisan migrate
```

## Quick Start

```php
use Cline\Fuse\Facades\Fuse;

// Protect an external API call
$result = Fuse::make('external-api')->call(function () {
    return Http::get('https://api.example.com/data');
});
```

That's it! Fuse will automatically:
- Track successes and failures
- Open the circuit after 5 consecutive failures (default)
- Reject requests for 60 seconds when open
- Attempt recovery in half-open state
- Close when service recovers

## Core Concepts

### Circuit States

- **CLOSED** - Normal operation, requests pass through
- **OPEN** - Too many failures detected, requests immediately rejected
- **HALF_OPEN** - Testing if service recovered, limited requests allowed

### Storage Drivers

#### Array Driver (In-Memory)
Perfect for testing and development:

```php
Fuse::store('array')->make('service')->call($callable);
```

#### Cache Driver (Recommended for Production)
Leverages Laravel's cache system with Redis/Memcached:

```php
Fuse::store('cache')->make('service')->call($callable);
```

#### Database Driver
Persistent storage with full audit trail:

```php
Fuse::store('database')->make('service')->call($callable);
```

### Evaluation Strategies

#### Consecutive Failures (Default)
Opens after N consecutive failures:

```php
Fuse::make('api', strategyName: 'consecutive_failures')
    ->call($callable);
```

#### Percentage Failures
Opens when failure rate exceeds threshold:

```php
Fuse::make('api', strategyName: 'percentage_failures')
    ->call($callable);
```

#### Rolling Window
Evaluates failures over a sliding time window:

```php
Fuse::make('api', strategyName: 'rolling_window')
    ->call($callable);
```

## Configuration

### Per-Service Configuration

```php
use Cline\Fuse\ValueObjects\CircuitBreakerConfiguration;

$config = CircuitBreakerConfiguration::fromDefaults('external-api')
    ->withFailureThreshold(10)      // Open after 10 failures
    ->withSuccessThreshold(3)       // Close after 3 successes
    ->withTimeout(120)              // Stay open for 2 minutes
    ->withStrategy('percentage_failures');

$breaker = Fuse::make('external-api', configuration: $config);
```

### Global Defaults

Edit `config/fuse.php`:

```php
'defaults' => [
    'failure_threshold' => 5,        // Consecutive failures to open
    'success_threshold' => 2,        // Consecutive successes to close
    'timeout' => 60,                 // Seconds to stay open
    'sampling_duration' => 120,      // Time window for percentage/rolling
    'minimum_throughput' => 10,      // Min requests before percentage applies
    'percentage_threshold' => 50,    // Failure percentage to open
],
```

## Advanced Usage

### Fallback Handlers

Provide graceful degradation when circuits open:

```php
// Global fallback in config/fuse.php
'fallbacks' => [
    'enabled' => true,
    'handlers' => [
        'external-api' => fn () => ['status' => 'unavailable', 'cached' => true],
    ],
],

// Handle in code
try {
    $result = Fuse::make('api')->call($callable);
} catch (CircuitBreakerOpenException $e) {
    if ($e->hasFallback()) {
        return $e->fallbackValue;
    }
    // Handle circuit open state
}
```

### Exception Filtering

Control which exceptions trigger failures:

```php
// In config/fuse.php
'exceptions' => [
    'ignore' => [
        // Don't count these as failures
        ValidationException::class,
        NotFoundHttpException::class,
    ],
    'record' => [
        // Only count these as failures
        ConnectionException::class,
        TimeoutException::class,
    ],
],
```

### Monitoring & Events

Listen for circuit breaker events:

```php
Event::listen(CircuitBreakerOpened::class, function ($event) {
    Log::warning("Circuit breaker {$event->name} opened");
    Notification::send($admins, new CircuitBreakerAlert($event));
});

Event::listen(CircuitBreakerClosed::class, function ($event) {
    Log::info("Circuit breaker {$event->name} recovered");
});
```

### Metrics & Status

```php
$breaker = Fuse::make('external-api');

// Get current state
$state = $breaker->getState(); // CLOSED, OPEN, or HALF_OPEN

// Get metrics
$metrics = $breaker->getMetrics();
echo "Failure rate: {$metrics->failureRate()}%";
echo "Total failures: {$metrics->totalFailures}";
echo "Last failure: {$metrics->lastFailureTime}";

// Manual reset
$breaker->reset();
```

### Multiple Stores

Configure different stores for different services:

```php
// In config/fuse.php
'stores' => [
    'redis' => [
        'driver' => 'cache',
        'store' => 'redis',
        'prefix' => 'cb',
    ],
    'critical' => [
        'driver' => 'database',
        'connection' => 'mysql',
    ],
],

// Use different stores
Fuse::store('redis')->make('api')->call($callable);
Fuse::store('critical')->make('payment')->call($callable);
```

### Custom Strategies

Implement your own evaluation logic:

```php
use Cline\Fuse\Contracts\Strategy;

class CustomStrategy implements Strategy
{
    public function shouldOpen(
        CircuitBreakerMetrics $metrics,
        CircuitBreakerConfiguration $configuration
    ): bool {
        // Your custom logic here
        return $metrics->consecutiveFailures > 3
            && $metrics->failureRate() > 25;
    }
}

// Register in service provider
Fuse::extend('custom', fn () => new CustomStrategy());

// Use your strategy
Fuse::make('service', strategyName: 'custom')->call($callable);
```

## Laravel Octane Support

Fuse automatically resets state between requests in Octane:

```php
// In config/fuse.php
'register_octane_reset_listener' => env('FUSE_REGISTER_OCTANE_RESET_LISTENER', true),
```

## Testing

```bash
# Run full test suite
composer test

# Individual tests
composer test:unit
composer test:types
composer test:lint
```

## Use Cases

### External API Integration
```php
public function fetchUserData($userId)
{
    return Fuse::make('user-api')->call(function () use ($userId) {
        return Http::timeout(5)
            ->get("https://api.example.com/users/{$userId}")
            ->throw()
            ->json();
    });
}
```

### Database Queries
```php
public function expensiveReport()
{
    return Fuse::make('reporting-db')->call(function () {
        return DB::connection('analytics')
            ->table('events')
            ->where('created_at', '>', now()->subDays(30))
            ->selectRaw('COUNT(*) as total, DATE(created_at) as date')
            ->groupBy('date')
            ->get();
    });
}
```

### Microservices Communication
```php
public function callPaymentService($orderId)
{
    return Fuse::make('payment-service', configuration:
        CircuitBreakerConfiguration::fromDefaults('payment-service')
            ->withFailureThreshold(3)
            ->withTimeout(30)
    )->call(function () use ($orderId) {
        return Http::post('http://payment-service/charge', [
            'order_id' => $orderId,
        ])->json();
    });
}
```

## Comparison with Other Packages

| Feature | Fuse | Alternatives |
|---------|------|--------------|
| Multiple storage drivers | ✅ | Limited |
| Multiple strategies | ✅ | Single strategy |
| Laravel 12 support | ✅ | Varies |
| Octane support | ✅ | ❌ |
| Fallback handlers | ✅ | ❌ |
| Exception filtering | ✅ | ❌ |
| Event system | ✅ | Limited |
| Audit trail | ✅ | ❌ |
| Type coverage | 100% | Varies |

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email brian@cline.sh instead of using the issue tracker.

## Credits

- [Brian Faust](https://github.com/faustbrian)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
