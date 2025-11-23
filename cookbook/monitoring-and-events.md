# Monitoring and Events

Fuse provides comprehensive event system and metrics tracking to help you monitor circuit breaker behavior in production.

## Events

Fuse dispatches events for all significant circuit breaker operations. Listen to these events to integrate with monitoring systems, send alerts, or track metrics.

### Available Events

| Event | When It Fires | Payload |
|-------|---------------|---------|
| `CircuitBreakerOpened` | Circuit transitions to OPEN | `name` |
| `CircuitBreakerClosed` | Circuit transitions to CLOSED | `name` |
| `CircuitBreakerHalfOpened` | Circuit transitions to HALF_OPEN | `name` |
| `CircuitBreakerRequestAttempted` | Before each request | `name`, `state` |
| `CircuitBreakerRequestSucceeded` | After successful request | `name`, `state` |
| `CircuitBreakerRequestFailed` | After failed request | `name`, `state` |

### Enabling Events

Events are enabled by default. Configure in `config/fuse.php`:

```php
'events' => [
    'enabled' => env('FUSE_EVENTS_ENABLED', true),
],
```

```env
FUSE_EVENTS_ENABLED=true
```

## Listening to Events

### Register Event Listeners

In `EventServiceProvider`:

```php
use Cline\Fuse\Events\CircuitBreakerOpened;
use Cline\Fuse\Events\CircuitBreakerClosed;
use App\Listeners\AlertOnCircuitOpened;
use App\Listeners\LogCircuitRecovery;

protected $listen = [
    CircuitBreakerOpened::class => [
        AlertOnCircuitOpened::class,
    ],
    CircuitBreakerClosed::class => [
        LogCircuitRecovery::class,
    ],
];
```

### Circuit Opened Event

When a circuit breaker opens:

```php
namespace App\Listeners;

use Cline\Fuse\Events\CircuitBreakerOpened;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class AlertOnCircuitOpened
{
    public function handle(CircuitBreakerOpened $event): void
    {
        Log::warning("Circuit breaker opened", [
            'circuit' => $event->name,
            'timestamp' => now(),
        ]);

        // Send alert to team
        Notification::send(
            User::admins()->get(),
            new CircuitBreakerAlert($event->name, 'opened')
        );

        // Track metric
        Metrics::increment('circuit_breaker.opened', [
            'service' => $event->name,
        ]);
    }
}
```

### Circuit Closed Event

When a circuit breaker closes (recovers):

```php
use Cline\Fuse\Events\CircuitBreakerClosed;

class LogCircuitRecovery
{
    public function handle(CircuitBreakerClosed $event): void
    {
        Log::info("Circuit breaker recovered", [
            'circuit' => $event->name,
            'timestamp' => now(),
        ]);

        // Clear alert
        Cache::forget("alert:circuit:{$event->name}");

        // Track recovery
        Metrics::increment('circuit_breaker.recovered', [
            'service' => $event->name,
        ]);
    }
}
```

### Circuit Half-Opened Event

When circuit enters half-open state:

```php
use Cline\Fuse\Events\CircuitBreakerHalfOpened;

class MonitorCircuitTesting
{
    public function handle(CircuitBreakerHalfOpened $event): void
    {
        Log::info("Circuit breaker testing recovery", [
            'circuit' => $event->name,
        ]);

        Metrics::increment('circuit_breaker.half_opened', [
            'service' => $event->name,
        ]);
    }
}
```

### Request Events

Track individual request outcomes:

```php
use Cline\Fuse\Events\CircuitBreakerRequestSucceeded;
use Cline\Fuse\Events\CircuitBreakerRequestFailed;

// Success
Event::listen(CircuitBreakerRequestSucceeded::class, function ($event) {
    Metrics::increment('circuit_breaker.request.success', [
        'service' => $event->name,
        'state' => $event->state->name,
    ]);
});

// Failure
Event::listen(CircuitBreakerRequestFailed::class, function ($event) {
    Metrics::increment('circuit_breaker.request.failure', [
        'service' => $event->name,
        'state' => $event->state->name,
    ]);
});
```

## Inline Event Listeners

### Using Closures

Quick inline listeners without creating classes:

```php
use Illuminate\Support\Facades\Event;
use Cline\Fuse\Events\CircuitBreakerOpened;

Event::listen(CircuitBreakerOpened::class, function ($event) {
    Log::critical("CIRCUIT OPEN: {$event->name}");

    // Send Slack notification
    Slack::send("Circuit breaker '{$event->name}' opened!");

    // Create incident ticket
    Incident::create([
        'type' => 'circuit_breaker_open',
        'service' => $event->name,
        'severity' => 'high',
    ]);
});
```

### Service Provider Registration

In `AppServiceProvider`:

```php
use Cline\Fuse\Events\CircuitBreakerOpened;
use Cline\Fuse\Events\CircuitBreakerClosed;

public function boot(): void
{
    Event::listen(CircuitBreakerOpened::class, function ($event) {
        // Alert logic
    });

    Event::listen(CircuitBreakerClosed::class, function ($event) {
        // Recovery logic
    });
}
```

## Metrics and Monitoring

### Get Circuit Metrics

```php
use Cline\Fuse\Facades\Fuse;

$breaker = Fuse::make('external-api');
$metrics = $breaker->getMetrics();

// Available metrics
echo "Total failures: {$metrics->totalFailures}";
echo "Total successes: {$metrics->totalSuccesses}";
echo "Consecutive failures: {$metrics->consecutiveFailures}";
echo "Consecutive successes: {$metrics->consecutiveSuccesses}";
echo "Last failure time: {$metrics->lastFailureTime}";
echo "Last success time: {$metrics->lastSuccessTime}";
echo "Failure rate: {$metrics->failureRate()}%";
```

### Check if Sufficient Throughput

```php
$metrics = $breaker->getMetrics();

if ($metrics->hasSufficientThroughput(10)) {
    echo "At least 10 requests recorded";
}
```

### Dashboard Example

```php
class CircuitBreakerController
{
    public function dashboard()
    {
        $circuits = ['payment-api', 'user-service', 'analytics-api'];
        $status = [];

        foreach ($circuits as $name) {
            $breaker = Fuse::make($name);
            $metrics = $breaker->getMetrics();
            $state = $breaker->getState();

            $status[] = [
                'name' => $name,
                'state' => $state->name,
                'is_healthy' => $state->isClosed(),
                'failure_rate' => $metrics->failureRate(),
                'total_requests' => $metrics->totalFailures + $metrics->totalSuccesses,
                'consecutive_failures' => $metrics->consecutiveFailures,
                'last_failure' => $metrics->lastFailureTime
                    ? Carbon::createFromTimestamp($metrics->lastFailureTime)
                    : null,
            ];
        }

        return view('admin.circuit-breakers', compact('status'));
    }
}
```

## Integration with Monitoring Services

### Prometheus

```php
use Cline\Fuse\Events\CircuitBreakerOpened;
use Cline\Fuse\Events\CircuitBreakerRequestFailed;
use Cline\Fuse\Events\CircuitBreakerRequestSucceeded;
use Prometheus\CollectorRegistry;

Event::listen(CircuitBreakerOpened::class, function ($event) {
    $registry = app(CollectorRegistry::class);

    $counter = $registry->getOrRegisterCounter(
        'app',
        'circuit_breaker_opened_total',
        'Circuit breaker opened events',
        ['service']
    );

    $counter->inc(['service' => $event->name]);
});

Event::listen(CircuitBreakerRequestFailed::class, function ($event) {
    $registry = app(CollectorRegistry::class);

    $counter = $registry->getOrRegisterCounter(
        'app',
        'circuit_breaker_request_failures_total',
        'Failed requests',
        ['service', 'state']
    );

    $counter->inc([
        'service' => $event->name,
        'state' => $event->state->name,
    ]);
});
```

### DataDog

```php
use DataDog\DogStatsd;
use Cline\Fuse\Events\CircuitBreakerOpened;

Event::listen(CircuitBreakerOpened::class, function ($event) {
    $statsd = app(DogStatsd::class);

    $statsd->increment('circuit_breaker.opened', 1, [
        'service' => $event->name,
    ]);

    $statsd->event(
        "Circuit breaker opened: {$event->name}",
        "Circuit breaker for {$event->name} has opened",
        ['alert_type' => 'error']
    );
});
```

### New Relic

```php
use Cline\Fuse\Events\CircuitBreakerOpened;

Event::listen(CircuitBreakerOpened::class, function ($event) {
    if (extension_loaded('newrelic')) {
        newrelic_record_custom_event('CircuitBreakerOpened', [
            'service' => $event->name,
            'timestamp' => time(),
        ]);
    }
});
```

### CloudWatch

```php
use Aws\CloudWatch\CloudWatchClient;
use Cline\Fuse\Events\CircuitBreakerRequestFailed;

Event::listen(CircuitBreakerRequestFailed::class, function ($event) {
    $cloudwatch = app(CloudWatchClient::class);

    $cloudwatch->putMetricData([
        'Namespace' => 'App/CircuitBreakers',
        'MetricData' => [
            [
                'MetricName' => 'RequestFailures',
                'Value' => 1,
                'Unit' => 'Count',
                'Dimensions' => [
                    ['Name' => 'Service', 'Value' => $event->name],
                    ['Name' => 'State', 'Value' => $event->state->name],
                ],
            ],
        ],
    ]);
});
```

### Sentry

```php
use Sentry\State\Scope;
use Cline\Fuse\Events\CircuitBreakerOpened;

Event::listen(CircuitBreakerOpened::class, function ($event) {
    \Sentry\withScope(function (Scope $scope) use ($event) {
        $scope->setTag('circuit_breaker', $event->name);
        $scope->setLevel('warning');

        \Sentry\captureMessage("Circuit breaker opened: {$event->name}");
    });
});
```

## Alert Strategies

### Immediate Alerts

Alert immediately when circuit opens:

```php
use Cline\Fuse\Events\CircuitBreakerOpened;

Event::listen(CircuitBreakerOpened::class, function ($event) {
    // Slack
    Slack::send("🚨 Circuit breaker '{$event->name}' OPENED");

    // Email
    Mail::to(config('alerts.email'))
        ->send(new CircuitBreakerOpenedMail($event->name));

    // SMS for critical services
    if (in_array($event->name, ['payment-gateway', 'auth-service'])) {
        Twilio::sendSms(config('alerts.phone'), "CRITICAL: {$event->name} circuit opened");
    }
});
```

### Throttled Alerts

Prevent alert spam:

```php
use Illuminate\Support\Facades\RateLimiter;
use Cline\Fuse\Events\CircuitBreakerOpened;

Event::listen(CircuitBreakerOpened::class, function ($event) {
    $key = "circuit-alert:{$event->name}";

    // Only alert once per 5 minutes
    if (RateLimiter::tooManyAttempts($key, 1)) {
        return;
    }

    RateLimiter::hit($key, 300); // 5 minutes

    Slack::send("Circuit breaker '{$event->name}' opened");
});
```

### Escalation Alerts

Escalate based on duration:

```php
use Cline\Fuse\Events\CircuitBreakerOpened;

Event::listen(CircuitBreakerOpened::class, function ($event) {
    Cache::put("circuit:opened:{$event->name}", now(), 3600);

    // Check again in 5 minutes
    dispatch(function () use ($event) {
        if (Cache::has("circuit:opened:{$event->name}")) {
            // Still open after 5 minutes
            Slack::sendToChannel('#incidents', "Circuit still open: {$event->name}");
        }
    })->delay(now()->addMinutes(5));

    // Check again in 15 minutes for escalation
    dispatch(function () use ($event) {
        if (Cache::has("circuit:opened:{$event->name}")) {
            // Still open after 15 minutes - escalate
            PagerDuty::triggerIncident("Circuit breaker critical: {$event->name}");
        }
    })->delay(now()->addMinutes(15));
});
```

## Logging

### Structured Logging

```php
use Cline\Fuse\Events\CircuitBreakerOpened;
use Cline\Fuse\Events\CircuitBreakerClosed;

Event::listen(CircuitBreakerOpened::class, function ($event) {
    Log::warning('Circuit breaker opened', [
        'event' => 'circuit_breaker_opened',
        'circuit' => $event->name,
        'timestamp' => now()->toIso8601String(),
        'severity' => 'warning',
    ]);
});

Event::listen(CircuitBreakerClosed::class, function ($event) {
    Log::info('Circuit breaker closed', [
        'event' => 'circuit_breaker_closed',
        'circuit' => $event->name,
        'timestamp' => now()->toIso8601String(),
    ]);
});
```

### Custom Log Channels

In `config/logging.php`:

```php
'channels' => [
    'circuit_breakers' => [
        'driver' => 'daily',
        'path' => storage_path('logs/circuit-breakers.log'),
        'level' => 'info',
        'days' => 30,
    ],
],
```

Usage:

```php
Event::listen(CircuitBreakerOpened::class, function ($event) {
    Log::channel('circuit_breakers')->warning("Opened: {$event->name}");
});
```

## Database Event History

Query historical events using the database driver:

```php
use Cline\Fuse\Database\CircuitBreakerEvent;

// Get all events for a circuit
$events = CircuitBreakerEvent::whereHas('circuitBreaker', function ($query) {
    $query->where('name', 'external-api');
})->latest()->get();

// Count opens today
$opensToday = CircuitBreakerEvent::where('event', 'opened')
    ->whereDate('created_at', today())
    ->count();

// Get recent state transitions
$transitions = CircuitBreakerEvent::whereIn('event', ['opened', 'closed', 'half_opened'])
    ->latest()
    ->take(100)
    ->get();

// Services with most opens this week
$problematic = CircuitBreakerEvent::where('event', 'opened')
    ->where('created_at', '>', now()->subWeek())
    ->groupBy('circuit_breaker_id')
    ->selectRaw('circuit_breaker_id, COUNT(*) as open_count')
    ->orderByDesc('open_count')
    ->get();
```

## Health Check Endpoint

Create an endpoint to monitor circuit breaker health:

```php
// routes/web.php
Route::get('/health/circuit-breakers', [HealthController::class, 'circuitBreakers']);

// app/Http/Controllers/HealthController.php
class HealthController
{
    public function circuitBreakers()
    {
        $circuits = ['payment-api', 'user-service', 'analytics-api'];
        $allHealthy = true;

        $status = collect($circuits)->map(function ($name) use (&$allHealthy) {
            $breaker = Fuse::make($name);
            $state = $breaker->getState();
            $metrics = $breaker->getMetrics();

            $isHealthy = $state->isClosed() && $metrics->failureRate() < 25;

            if (!$isHealthy) {
                $allHealthy = false;
            }

            return [
                'name' => $name,
                'healthy' => $isHealthy,
                'state' => $state->name,
                'failure_rate' => $metrics->failureRate(),
            ];
        });

        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'degraded',
            'circuits' => $status,
        ], $allHealthy ? 200 : 503);
    }
}
```

## Metrics Pruning

Clean up old metrics regularly:

```php
// app/Console/Commands/PruneCircuitBreakerMetrics.php
class PruneCircuitBreakerMetrics extends Command
{
    protected $signature = 'fuse:prune-metrics {--days=30}';

    public function handle(): void
    {
        $days = $this->option('days');

        $deleted = CircuitBreakerEvent::where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} circuit breaker events older than {$days} days");
    }
}

// Register in Kernel.php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('fuse:prune-metrics')->daily();
}
```

## Best Practices

1. **Alert Selectively**
   - Critical services: immediate alerts
   - Non-critical: log only
   - Use rate limiting to prevent spam

2. **Monitor Trends**
   - Track failure rates over time
   - Alert on increasing failure patterns
   - Review metrics weekly

3. **Log Appropriately**
   - Opens: WARNING level
   - Closes: INFO level
   - Failures: DEBUG level

4. **Integrate with Existing Tools**
   - Use your existing monitoring stack
   - Centralize circuit breaker metrics
   - Include in overall service health

5. **Test Monitoring**
   - Verify alerts fire correctly
   - Test escalation paths
   - Document response procedures

## Next Steps

- **[Exception Handling](exception-handling.md)** - Control which exceptions trigger events
- **[Testing](testing.md)** - Test event listeners
- **[Use Cases](use-cases.md)** - Real-world monitoring examples
