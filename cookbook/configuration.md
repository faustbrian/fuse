# Configuration

A comprehensive guide to all configuration options available in Fuse.

## Configuration File

The main configuration file is located at `config/fuse.php`. Publish it with:

```bash
php artisan vendor:publish --tag=fuse-config
```

## Default Store

Set which storage driver to use by default:

```php
'default' => env('FUSE_STORE', 'cache'),
```

Environment variable:
```env
FUSE_STORE=cache
```

Options: `array`, `cache`, `database`

## Primary Key Type

Control the type of primary key used in database tables:

```php
'primary_key_type' => env('FUSE_PRIMARY_KEY_TYPE', 'id'),
```

Options:
- `id` - Traditional auto-incrementing integers
- `ulid` - Universally Unique Lexicographically Sortable Identifier
- `uuid` - Universally Unique Identifier

```env
FUSE_PRIMARY_KEY_TYPE=ulid
```

## Eloquent Models

Customize which models Fuse uses:

```php
'models' => [
    'circuit_breaker' => \Cline\Fuse\Database\CircuitBreaker::class,
    'circuit_breaker_event' => \Cline\Fuse\Database\CircuitBreakerEvent::class,
],
```

### Custom Models

Extend the base models:

```php
namespace App\Models;

use Cline\Fuse\Database\CircuitBreaker as BaseCircuitBreaker;

class CircuitBreaker extends BaseCircuitBreaker
{
    protected $table = 'custom_circuit_breakers';

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('state', 'OPEN');
    }
}
```

Register in config:

```php
'models' => [
    'circuit_breaker' => \App\Models\CircuitBreaker::class,
],
```

## Table Names

Customize database table names:

```php
'table_names' => [
    'circuit_breakers' => env('FUSE_CIRCUIT_BREAKERS_TABLE', 'circuit_breakers'),
    'circuit_breaker_events' => env('FUSE_CIRCUIT_BREAKER_EVENTS_TABLE', 'circuit_breaker_events'),
],
```

Environment variables:
```env
FUSE_CIRCUIT_BREAKERS_TABLE=my_circuit_breakers
FUSE_CIRCUIT_BREAKER_EVENTS_TABLE=my_circuit_breaker_events
```

## Storage Drivers

### Array Store

```php
'stores' => [
    'array' => [
        'driver' => 'array',
    ],
],
```

No additional configuration needed.

### Database Store

```php
'stores' => [
    'database' => [
        'driver' => 'database',
        'connection' => env('DB_CONNECTION'),
    ],
],
```

Environment variable:
```env
DB_CONNECTION=mysql
```

### Cache Store

```php
'stores' => [
    'cache' => [
        'driver' => 'cache',
        'store' => env('FUSE_CACHE_STORE'),
        'prefix' => env('FUSE_CACHE_PREFIX', 'circuit_breaker'),
    ],
],
```

Environment variables:
```env
FUSE_CACHE_STORE=redis
FUSE_CACHE_PREFIX=cb
```

### Multiple Stores

Define multiple stores for different purposes:

```php
'stores' => [
    'redis' => [
        'driver' => 'cache',
        'store' => 'redis',
        'prefix' => 'cb',
    ],
    'memcached' => [
        'driver' => 'cache',
        'store' => 'memcached',
        'prefix' => 'cb',
    ],
    'critical' => [
        'driver' => 'database',
        'connection' => 'mysql',
    ],
    'audit' => [
        'driver' => 'database',
        'connection' => 'audit_db',
    ],
],
```

## Default Circuit Breaker Settings

These defaults apply to all circuit breakers unless overridden:

### Failure Threshold

Number of consecutive failures to open the circuit:

```php
'defaults' => [
    'failure_threshold' => env('FUSE_FAILURE_THRESHOLD', 5),
],
```

```env
FUSE_FAILURE_THRESHOLD=5
```

- Lower = more sensitive
- Higher = more tolerant
- Recommended: 3-10

### Success Threshold

Number of consecutive successes to close the circuit from half-open:

```php
'defaults' => [
    'success_threshold' => env('FUSE_SUCCESS_THRESHOLD', 2),
],
```

```env
FUSE_SUCCESS_THRESHOLD=2
```

- Lower = faster recovery
- Higher = more cautious recovery
- Recommended: 2-5

### Timeout

Seconds to remain open before attempting recovery:

```php
'defaults' => [
    'timeout' => env('FUSE_TIMEOUT', 60),
],
```

```env
FUSE_TIMEOUT=60
```

- Lower = faster retry
- Higher = more recovery time
- Recommended: 30-120 seconds

### Sampling Duration

Time window for percentage and rolling window strategies:

```php
'defaults' => [
    'sampling_duration' => env('FUSE_SAMPLING_DURATION', 120),
],
```

```env
FUSE_SAMPLING_DURATION=120
```

- Lower = more reactive
- Higher = more stable
- Recommended: 60-300 seconds

### Minimum Throughput

Minimum requests before percentage calculations apply:

```php
'defaults' => [
    'minimum_throughput' => env('FUSE_MINIMUM_THROUGHPUT', 10),
],
```

```env
FUSE_MINIMUM_THROUGHPUT=10
```

- Lower = faster detection
- Higher = more samples needed
- Recommended: 5-20 requests

### Percentage Threshold

Failure rate percentage to open circuit:

```php
'defaults' => [
    'percentage_threshold' => env('FUSE_PERCENTAGE_THRESHOLD', 50),
],
```

```env
FUSE_PERCENTAGE_THRESHOLD=50
```

- Lower = more sensitive (25-40%)
- Medium = balanced (50%)
- Higher = more tolerant (60-75%)

## Evaluation Strategies

### Default Strategy

```php
'strategies' => [
    'default' => env('FUSE_DEFAULT_STRATEGY', 'consecutive_failures'),
],
```

```env
FUSE_DEFAULT_STRATEGY=consecutive_failures
```

Options:
- `consecutive_failures`
- `percentage_failures`
- `rolling_window`

### Available Strategies

```php
'strategies' => [
    'available' => [
        'consecutive_failures' => \Cline\Fuse\Strategies\ConsecutiveFailuresStrategy::class,
        'percentage_failures' => \Cline\Fuse\Strategies\PercentageFailuresStrategy::class,
        'rolling_window' => \Cline\Fuse\Strategies\RollingWindowStrategy::class,
    ],
],
```

### Custom Strategies

Add your own strategies:

```php
'strategies' => [
    'available' => [
        'consecutive_failures' => \Cline\Fuse\Strategies\ConsecutiveFailuresStrategy::class,
        'percentage_failures' => \Cline\Fuse\Strategies\PercentageFailuresStrategy::class,
        'rolling_window' => \Cline\Fuse\Strategies\RollingWindowStrategy::class,
        'custom' => \App\CircuitBreaker\CustomStrategy::class,
    ],
],
```

## Octane Support

Enable automatic state reset for Laravel Octane:

```php
'register_octane_reset_listener' => env('FUSE_REGISTER_OCTANE_RESET_LISTENER', true),
```

```env
FUSE_REGISTER_OCTANE_RESET_LISTENER=true
```

Set to `false` if you're managing Octane resets manually.

## Events Configuration

Control event dispatching:

```php
'events' => [
    'enabled' => env('FUSE_EVENTS_ENABLED', true),
],
```

```env
FUSE_EVENTS_ENABLED=true
```

When enabled, Fuse dispatches:
- `CircuitBreakerOpened`
- `CircuitBreakerClosed`
- `CircuitBreakerHalfOpened`
- `CircuitBreakerRequestAttempted`
- `CircuitBreakerRequestSucceeded`
- `CircuitBreakerRequestFailed`

Disable for performance in high-throughput scenarios.

## Model Observers

Control Eloquent observer registration:

```php
'observers' => [
    'enabled' => env('FUSE_OBSERVERS_ENABLED', true),
],
```

```env
FUSE_OBSERVERS_ENABLED=true
```

Observers handle:
- Cache invalidation
- Data consistency
- Related model updates

## Fallback Handlers

### Enable Fallbacks

```php
'fallbacks' => [
    'enabled' => env('FUSE_FALLBACKS_ENABLED', true),
],
```

```env
FUSE_FALLBACKS_ENABLED=true
```

### Default Fallback

Global fallback for all circuit breakers:

```php
'fallbacks' => [
    'default' => fn ($name) => [
        'status' => 'unavailable',
        'circuit' => $name,
    ],
],
```

Set to `null` to throw exceptions instead.

### Service-Specific Fallbacks

```php
'fallbacks' => [
    'handlers' => [
        'external-api' => fn () => ['status' => 'unavailable'],
        'user-service' => fn () => Cache::get('users-cached', []),
        'payment-gateway' => fn () => throw new ServiceUnavailableException(),
    ],
],
```

## Monitoring Configuration

### Enable Monitoring

```php
'monitoring' => [
    'enabled' => env('FUSE_MONITORING_ENABLED', true),
],
```

```env
FUSE_MONITORING_ENABLED=true
```

### Metrics Retention

Days to retain circuit breaker metrics:

```php
'monitoring' => [
    'retention_days' => env('FUSE_METRICS_RETENTION_DAYS', 30),
],
```

```env
FUSE_METRICS_RETENTION_DAYS=30
```

Set to `0` to disable automatic pruning.

## Exception Handling

### Ignored Exceptions

Exceptions that won't be counted as failures:

```php
'exceptions' => [
    'ignore' => [
        \Illuminate\Validation\ValidationException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Illuminate\Auth\AuthenticationException::class,
    ],
],
```

### Recorded Exceptions

Only these exceptions count as failures (whitelist):

```php
'exceptions' => [
    'record' => [
        \Illuminate\Http\Client\ConnectionException::class,
        \GuzzleHttp\Exception\ConnectException::class,
        \Illuminate\Database\QueryException::class,
    ],
],
```

Leave empty to record all exceptions except ignored ones.

## Environment-Specific Configuration

### Local Development

```env
FUSE_STORE=array
FUSE_FAILURE_THRESHOLD=100
FUSE_TIMEOUT=10
FUSE_EVENTS_ENABLED=false
```

### Testing

```env
FUSE_STORE=array
FUSE_EVENTS_ENABLED=false
FUSE_OBSERVERS_ENABLED=false
```

### Staging

```env
FUSE_STORE=cache
FUSE_CACHE_STORE=redis
FUSE_FAILURE_THRESHOLD=10
FUSE_TIMEOUT=30
```

### Production

```env
FUSE_STORE=cache
FUSE_CACHE_STORE=redis
FUSE_FAILURE_THRESHOLD=5
FUSE_TIMEOUT=60
FUSE_EVENTS_ENABLED=true
FUSE_MONITORING_ENABLED=true
```

### Production with Compliance

```env
FUSE_STORE=database
FUSE_FAILURE_THRESHOLD=5
FUSE_TIMEOUT=60
FUSE_EVENTS_ENABLED=true
FUSE_MONITORING_ENABLED=true
FUSE_METRICS_RETENTION_DAYS=90
```

## Per-Circuit Configuration

Override defaults for specific circuit breakers:

```php
use Cline\Fuse\ValueObjects\CircuitBreakerConfiguration;
use Cline\Fuse\Facades\Fuse;

// Payment service: strict settings
$paymentConfig = CircuitBreakerConfiguration::fromDefaults('payment')
    ->withFailureThreshold(2)
    ->withSuccessThreshold(5)
    ->withTimeout(180);

// Analytics service: lenient settings
$analyticsConfig = CircuitBreakerConfiguration::fromDefaults('analytics')
    ->withFailureThreshold(15)
    ->withSuccessThreshold(2)
    ->withTimeout(30);

// Use configurations
Fuse::make('payment', configuration: $paymentConfig)->call($callable);
Fuse::make('analytics', configuration: $analyticsConfig)->call($callable);
```

## Service Provider Configuration

Register circuit breakers in a service provider:

```php
namespace App\Providers;

use Cline\Fuse\Facades\Fuse;
use Cline\Fuse\ValueObjects\CircuitBreakerConfiguration;
use Illuminate\Support\ServiceProvider;

class CircuitBreakerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Register custom strategies
        Fuse::extend('time_sensitive', fn () => new TimeSensitiveStrategy());

        // Pre-configure common circuit breakers
        $this->app->singleton('cb.payment', function () {
            return Fuse::make('payment-gateway', configuration:
                CircuitBreakerConfiguration::fromDefaults('payment-gateway')
                    ->withFailureThreshold(3)
                    ->withTimeout(120)
            );
        });
    }
}
```

## Configuration Builder Pattern

Build configurations fluently:

```php
$config = CircuitBreakerConfiguration::fromDefaults('service')
    ->withFailureThreshold(10)
    ->withSuccessThreshold(3)
    ->withTimeout(120)
    ->withSamplingDuration(180)
    ->withMinimumThroughput(15)
    ->withPercentageThreshold(60);

// Or chain methods
$config = CircuitBreakerConfiguration::fromDefaults('service')
    ->withFailureThreshold(10)
    ->withSuccessThreshold(3)
    ->withTimeout(120);
```

## Complete Configuration Example

```php
<?php

return [
    'default' => 'cache',

    'primary_key_type' => 'ulid',

    'models' => [
        'circuit_breaker' => \App\Models\CircuitBreaker::class,
        'circuit_breaker_event' => \App\Models\CircuitBreakerEvent::class,
    ],

    'table_names' => [
        'circuit_breakers' => 'circuit_breakers',
        'circuit_breaker_events' => 'circuit_breaker_events',
    ],

    'stores' => [
        'array' => ['driver' => 'array'],
        'cache' => [
            'driver' => 'cache',
            'store' => 'redis',
            'prefix' => 'cb',
        ],
        'database' => [
            'driver' => 'database',
            'connection' => 'mysql',
        ],
    ],

    'defaults' => [
        'failure_threshold' => 5,
        'success_threshold' => 2,
        'timeout' => 60,
        'sampling_duration' => 120,
        'minimum_throughput' => 10,
        'percentage_threshold' => 50,
    ],

    'strategies' => [
        'default' => 'consecutive_failures',
        'available' => [
            'consecutive_failures' => \Cline\Fuse\Strategies\ConsecutiveFailuresStrategy::class,
            'percentage_failures' => \Cline\Fuse\Strategies\PercentageFailuresStrategy::class,
            'rolling_window' => \Cline\Fuse\Strategies\RollingWindowStrategy::class,
        ],
    ],

    'register_octane_reset_listener' => true,

    'events' => ['enabled' => true],

    'observers' => ['enabled' => true],

    'fallbacks' => [
        'enabled' => true,
        'default' => null,
        'handlers' => [],
    ],

    'monitoring' => [
        'enabled' => true,
        'retention_days' => 30,
    ],

    'exceptions' => [
        'ignore' => [],
        'record' => [],
    ],
];
```

## Best Practices

1. **Use Environment Variables**
   - Keep configuration flexible across environments
   - Store sensitive data in `.env`

2. **Start with Defaults**
   - Default values work well for most use cases
   - Only customize when you have specific needs

3. **Per-Service Configuration**
   - Critical services: strict thresholds
   - Optional services: lenient thresholds

4. **Monitor and Adjust**
   - Start conservative
   - Tune based on real-world metrics
   - Document changes and reasoning

5. **Separate Concerns**
   - Use different stores for different purposes
   - Critical services may need database audit trail
   - Regular services can use cache

## Next Steps

- **[Storage Drivers](storage-drivers.md)** - Deep dive into storage options
- **[Strategies](strategies.md)** - Choose the right evaluation strategy
- **[Fallback Handlers](fallback-handlers.md)** - Configure graceful degradation
- **[Monitoring & Events](monitoring-and-events.md)** - Track circuit breaker behavior
