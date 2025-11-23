# Strategies

Circuit breakers use evaluation strategies to determine when to open based on failure patterns. Fuse provides three built-in strategies, each suited for different scenarios.

## Overview

| Strategy | Opens When | Best For | Reacts To |
|----------|-----------|----------|-----------|
| Consecutive Failures | N failures in a row | Sudden outages | Immediate failures |
| Percentage Failures | Failure rate > X% | Degraded services | Overall reliability |
| Rolling Window | Failure rate > X% in time window | Intermittent issues | Recent history |

## Consecutive Failures Strategy

The simplest and most common strategy. Opens the circuit after a specified number of consecutive failures.

### How It Works

```
Request 1: ✓ Success
Request 2: ✗ Failure (count: 1)
Request 3: ✗ Failure (count: 2)
Request 4: ✓ Success (count: 0, reset)
Request 5: ✗ Failure (count: 1)
Request 6: ✗ Failure (count: 2)
Request 7: ✗ Failure (count: 3)
Request 8: ✗ Failure (count: 4)
Request 9: ✗ Failure (count: 5) → CIRCUIT OPENS
```

### Configuration

```php
use Cline\Fuse\Facades\Fuse;
use Cline\Fuse\ValueObjects\CircuitBreakerConfiguration;

$config = CircuitBreakerConfiguration::fromDefaults('external-api')
    ->withFailureThreshold(5); // Open after 5 consecutive failures

$result = Fuse::make('external-api', configuration: $config, strategyName: 'consecutive_failures')
    ->call($callable);
```

### When to Use

**Perfect for:**
- Detecting complete service outages
- When services either work or don't (binary states)
- Fast fail-over scenarios
- Network connectivity issues
- Services with simple failure modes

**Example scenarios:**
- Database connection lost
- API server is down
- External service unreachable
- Authentication service offline

### Tuning Thresholds

```php
// Very sensitive (development/testing)
->withFailureThreshold(2)

// Moderate (most production services)
->withFailureThreshold(5)

// Tolerant (non-critical services)
->withFailureThreshold(10)

// Very tolerant (optional features)
->withFailureThreshold(20)
```

### Real-World Example

```php
// Payment gateway - very sensitive
$paymentConfig = CircuitBreakerConfiguration::fromDefaults('payment-gateway')
    ->withFailureThreshold(3)      // Quick to detect issues
    ->withSuccessThreshold(5)      // Cautious to recover
    ->withTimeout(180);            // 3 minutes to recover

Fuse::make('payment-gateway', configuration: $paymentConfig, strategyName: 'consecutive_failures')
    ->call(function () {
        return $this->stripeClient->charges->create([...]);
    });
```

### Characteristics

**Pros:**
- Simple to understand
- Fast to react to complete failures
- Low overhead
- Predictable behavior
- Good for binary failure states

**Cons:**
- Doesn't detect gradual degradation
- Single success resets everything
- No memory of past failures
- Poor for intermittent issues
- Ignores overall failure rate

## Percentage Failures Strategy

Opens the circuit when the failure rate exceeds a threshold percentage, regardless of whether failures are consecutive.

### How It Works

```
Total requests: 20
Failures: 11
Success rate: 45%
Failure rate: 55%

Threshold: 50%
Result: 55% > 50% → CIRCUIT OPENS
```

### Configuration

```php
$config = CircuitBreakerConfiguration::fromDefaults('external-api')
    ->withPercentageThreshold(50)    // Open if failure rate > 50%
    ->withMinimumThroughput(10);     // Need at least 10 requests

$result = Fuse::make('external-api', configuration: $config, strategyName: 'percentage_failures')
    ->call($callable);
```

### Minimum Throughput

The minimum throughput prevents opening the circuit based on too few samples:

```php
// Without minimum throughput
1 request, 1 failure = 100% failure rate → Opens (bad!)

// With minimum throughput of 10
1 request, 1 failure = 100% BUT < 10 requests → Stays closed (good!)
```

### When to Use

**Perfect for:**
- Detecting gradual service degradation
- When services partially work
- Load-related issues
- Services with variable reliability
- When overall reliability matters more than consecutive failures

**Example scenarios:**
- API rate limiting
- Database connection pool exhaustion
- Overloaded services
- Intermittent network issues
- Degraded but not dead services

### Tuning Thresholds

```php
// Very sensitive
->withPercentageThreshold(25)      // Open at 25% failure rate
->withMinimumThroughput(20)        // Need 20 samples

// Moderate (recommended)
->withPercentageThreshold(50)      // Open at 50% failure rate
->withMinimumThroughput(10)        // Need 10 samples

// Tolerant
->withPercentageThreshold(75)      // Open at 75% failure rate
->withMinimumThroughput(5)         // Need 5 samples
```

### Real-World Example

```php
// Analytics API - can tolerate some failures
$analyticsConfig = CircuitBreakerConfiguration::fromDefaults('analytics-api')
    ->withPercentageThreshold(60)     // Tolerate up to 60% failures
    ->withMinimumThroughput(20)       // Need meaningful sample size
    ->withFailureThreshold(100)       // Not used, but required
    ->withTimeout(60);

Fuse::make('analytics-api', configuration: $analyticsConfig, strategyName: 'percentage_failures')
    ->call(function () {
        return Http::post('https://analytics.example.com/events', [...]);
    });
```

### Understanding Failure Rate

```php
$breaker = Fuse::make('api', strategyName: 'percentage_failures');
$metrics = $breaker->getMetrics();

echo "Total requests: " . ($metrics->totalFailures + $metrics->totalSuccesses);
echo "Failures: {$metrics->totalFailures}";
echo "Failure rate: {$metrics->failureRate()}%";
```

### Characteristics

**Pros:**
- Detects gradual degradation
- Handles intermittent failures well
- Considers overall reliability
- Less sensitive to single failures
- Better for variable services

**Cons:**
- Slower to react than consecutive
- Requires minimum throughput
- More complex to understand
- Needs tuning for sample size
- Can be too tolerant initially

## Rolling Window Strategy

Evaluates failures over a sliding time window. Only recent failures within the window are considered.

### How It Works

```
Window: Last 60 seconds
Current time: 10:00:00

09:59:00 - ✗ Failure (too old, ignored)
09:59:30 - ✗ Failure (within window, counted)
09:59:45 - ✓ Success (within window, counted)
10:00:00 - ✗ Failure (within window, counted)

Failures in window: 2
Successes in window: 1
Total in window: 3
Failure rate: 66%

Threshold: 50%
Result: 66% > 50% → CIRCUIT OPENS
```

### Configuration

```php
$config = CircuitBreakerConfiguration::fromDefaults('external-api')
    ->withSamplingDuration(120)      // 2-minute window
    ->withPercentageThreshold(50)    // Open if failure rate > 50%
    ->withMinimumThroughput(10);     // Need at least 10 requests

$result = Fuse::make('external-api', configuration: $config, strategyName: 'rolling_window')
    ->call($callable);
```

### When to Use

**Perfect for:**
- Time-sensitive failure detection
- Recovering from transient issues
- Services with burst failures
- When old failures shouldn't matter
- Rate-limited services

**Example scenarios:**
- API with rate limits that reset
- Services that auto-recover
- Scheduled maintenance windows
- Cloud services with variable latency
- Microservices with deployment cycles

### Tuning Parameters

```php
// Short window, quick recovery
->withSamplingDuration(30)         // 30-second window
->withPercentageThreshold(50)
->withMinimumThroughput(5)

// Medium window (recommended)
->withSamplingDuration(120)        // 2-minute window
->withPercentageThreshold(50)
->withMinimumThroughput(10)

// Long window, stable detection
->withSamplingDuration(300)        // 5-minute window
->withPercentageThreshold(40)
->withMinimumThroughput(20)
```

### Real-World Example

```php
// Third-party API with rate limits
$apiConfig = CircuitBreakerConfiguration::fromDefaults('rate-limited-api')
    ->withSamplingDuration(60)        // 1-minute window (matches rate limit window)
    ->withPercentageThreshold(50)
    ->withMinimumThroughput(10)
    ->withTimeout(60);                // Wait for rate limit to reset

Fuse::make('rate-limited-api', configuration: $apiConfig, strategyName: 'rolling_window')
    ->call(function () {
        return Http::get('https://api.example.com/data')->json();
    });
```

### Window Sliding Behavior

```php
// At 10:00:00 with 60-second window
// Considers failures from 09:59:00 to 10:00:00

// At 10:00:30 with 60-second window
// Considers failures from 09:59:30 to 10:00:30
// (failures before 09:59:30 are now forgotten)
```

### Characteristics

**Pros:**
- Forgets old failures
- Handles burst failures
- Good for time-based issues
- Natural recovery mechanism
- Matches rate limit windows

**Cons:**
- Most complex strategy
- Requires time tracking
- Higher memory overhead
- Needs careful window sizing
- Can miss patterns outside window

## Strategy Comparison

### Response Time to Failures

```php
// Consecutive: Opens after 5th consecutive failure
✗ ✗ ✗ ✗ ✗ → OPEN (5 requests)

// Percentage: Opens when rate exceeds threshold
✗ ✓ ✗ ✓ ✗ ✗ ✗ → OPEN (7 requests, 57% failure rate)

// Rolling Window: Opens when rate in window exceeds threshold
✗ ✓ ✗ ✓ ✗ ✗ ✗ → OPEN (7 requests in 60s, 57% in window)
```

### Memory of Past Failures

| Strategy | Remembers |
|----------|-----------|
| Consecutive | Only consecutive streak |
| Percentage | All failures (lifetime) |
| Rolling Window | Failures within window |

### Recovery Behavior

```php
// Consecutive: Single success resets counter
✗ ✗ ✗ ✗ ✓ → Back to 0

// Percentage: Success improves rate
✗ ✗ ✗ ✗ ✓ → 80% → 80% failure rate

// Rolling Window: Success improves rate in window
✗ ✗ ✗ ✗ ✓ → Depends on window
```

## Choosing the Right Strategy

### Decision Tree

```
Is the service binary (works or doesn't)?
├─ Yes → Consecutive Failures
└─ No → Are failures time-related?
    ├─ Yes → Rolling Window
    └─ No → Percentage Failures
```

### By Service Type

**External APIs** → Consecutive Failures
- Clear failure modes
- Fast detection needed
- Binary success/failure

**Payment Gateways** → Consecutive Failures
- Critical path
- Zero tolerance
- Immediate detection

**Analytics Services** → Percentage Failures
- Partial failures acceptable
- Overall reliability matters
- Non-critical

**Rate-Limited APIs** → Rolling Window
- Time-based limits
- Auto-recovery
- Window matches rate limit

**Microservices** → Rolling Window
- Deployment cycles
- Gradual degradation
- Time-based recovery

### By Failure Pattern

**Sudden Outages** → Consecutive Failures
```
✓ ✓ ✓ ✓ ✗ ✗ ✗ ✗ ✗
```

**Gradual Degradation** → Percentage Failures
```
✓ ✓ ✗ ✓ ✗ ✓ ✗ ✗ ✗
```

**Burst Failures** → Rolling Window
```
✗ ✗ ✗ ✗ ✓ ✓ ✓ ✓ ✓
```

**Intermittent Issues** → Percentage Failures or Rolling Window
```
✗ ✓ ✗ ✓ ✗ ✓ ✗ ✓ ✗
```

## Combining with Configuration

### Sensitive Service

```php
$config = CircuitBreakerConfiguration::fromDefaults('critical-api')
    ->withFailureThreshold(3)
    ->withSuccessThreshold(5)
    ->withTimeout(180);

Fuse::make('critical-api', configuration: $config, strategyName: 'consecutive_failures')
    ->call($callable);
```

### Degraded Service

```php
$config = CircuitBreakerConfiguration::fromDefaults('degraded-api')
    ->withPercentageThreshold(60)
    ->withMinimumThroughput(20)
    ->withTimeout(60);

Fuse::make('degraded-api', configuration: $config, strategyName: 'percentage_failures')
    ->call($callable);
```

### Rate-Limited Service

```php
$config = CircuitBreakerConfiguration::fromDefaults('rate-limited')
    ->withSamplingDuration(60)
    ->withPercentageThreshold(50)
    ->withMinimumThroughput(10)
    ->withTimeout(60);

Fuse::make('rate-limited', configuration: $config, strategyName: 'rolling_window')
    ->call($callable);
```

## Custom Strategies

You can implement custom evaluation strategies:

```php
use Cline\Fuse\Contracts\Strategy;
use Cline\Fuse\ValueObjects\CircuitBreakerMetrics;
use Cline\Fuse\ValueObjects\CircuitBreakerConfiguration;

class TimeOfDayStrategy implements Strategy
{
    public function shouldOpen(
        CircuitBreakerMetrics $metrics,
        CircuitBreakerConfiguration $configuration
    ): bool {
        // More sensitive during business hours
        $hour = (int) date('H');
        $isBusinessHours = $hour >= 9 && $hour <= 17;

        $threshold = $isBusinessHours ? 3 : 10;

        return $metrics->consecutiveFailures >= $threshold;
    }
}

// Register in service provider
use Cline\Fuse\Facades\Fuse;

Fuse::extend('time_of_day', fn () => new TimeOfDayStrategy());

// Use it
Fuse::make('api', strategyName: 'time_of_day')->call($callable);
```

## Testing Strategies

```php
use Tests\TestCase;
use Cline\Fuse\Facades\Fuse;

class StrategyTest extends TestCase
{
    public function test_consecutive_failures_opens_circuit()
    {
        $breaker = Fuse::store('array')
            ->make('test', strategyName: 'consecutive_failures');

        // Simulate 5 failures
        for ($i = 0; $i < 5; $i++) {
            try {
                $breaker->call(fn () => throw new \Exception());
            } catch (\Exception $e) {}
        }

        $this->assertTrue($breaker->getState()->isOpen());
    }

    public function test_percentage_requires_throughput()
    {
        $config = CircuitBreakerConfiguration::fromDefaults('test')
            ->withMinimumThroughput(10);

        $breaker = Fuse::store('array')
            ->make('test', configuration: $config, strategyName: 'percentage_failures');

        // 1 failure isn't enough
        try {
            $breaker->call(fn () => throw new \Exception());
        } catch (\Exception $e) {}

        $this->assertTrue($breaker->getState()->isClosed());
    }
}
```

## Next Steps

- **[Configuration](configuration.md)** - Detailed configuration guide
- **[Monitoring & Events](monitoring-and-events.md)** - Track strategy performance
- **[Advanced Usage](advanced-usage.md)** - Custom strategies and extensions
- **[Testing](testing.md)** - Test different strategies
