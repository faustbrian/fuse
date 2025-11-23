# Testing

Comprehensive guide to testing applications that use Fuse circuit breakers.

## Testing Configuration

### Test Environment Setup

Configure Fuse for testing in `phpunit.xml`:

```xml
<php>
    <env name="FUSE_STORE" value="array"/>
    <env name="FUSE_EVENTS_ENABLED" value="false"/>
    <env name="FUSE_OBSERVERS_ENABLED" value="false"/>
</php>
```

Or in your test case:

```php
use Tests\TestCase;

abstract class CircuitBreakerTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fuse.default' => 'array',
            'fuse.events.enabled' => false,
            'fuse.observers.enabled' => false,
        ]);
    }
}
```

## Testing Circuit Breaker Behavior

### Test Circuit Opens

```php
use Tests\TestCase;
use Cline\Fuse\Facades\Fuse;

class CircuitBreakerTest extends TestCase
{
    public function test_circuit_opens_after_failures()
    {
        $breaker = Fuse::store('array')->make('test-service');

        // Simulate 5 failures (default threshold)
        for ($i = 0; $i < 5; $i++) {
            try {
                $breaker->call(fn () => throw new \Exception('Test failure'));
            } catch (\Exception $e) {
                // Expected
            }
        }

        // Assert circuit is now open
        $this->assertTrue($breaker->getState()->isOpen());
    }
}
```

### Test Circuit Closes

```php
public function test_circuit_closes_after_recovery()
{
    $breaker = Fuse::store('array')->make('test-service');

    // Open the circuit
    for ($i = 0; $i < 5; $i++) {
        try {
            $breaker->call(fn () => throw new \Exception());
        } catch (\Exception $e) {}
    }

    $this->assertTrue($breaker->getState()->isOpen());

    // Reset and verify it closes
    $breaker->reset();

    $this->assertTrue($breaker->getState()->isClosed());
}
```

### Test Half-Open State

```php
public function test_circuit_enters_half_open_state()
{
    $config = CircuitBreakerConfiguration::fromDefaults('test')
        ->withTimeout(1); // 1 second timeout

    $breaker = Fuse::store('array')->make('test', configuration: $config);

    // Open circuit
    for ($i = 0; $i < 5; $i++) {
        try {
            $breaker->call(fn () => throw new \Exception());
        } catch (\Exception $e) {}
    }

    // Wait for timeout
    sleep(2);

    // Next call should transition to half-open, then fail
    try {
        $breaker->call(fn () => throw new \Exception());
    } catch (\Exception $e) {}

    // Circuit should be open again
    $this->assertTrue($breaker->getState()->isOpen());
}
```

## Testing with Fallbacks

### Test Fallback Execution

```php
public function test_fallback_is_used_when_circuit_opens()
{
    config([
        'fuse.fallbacks.enabled' => true,
        'fuse.fallbacks.handlers' => [
            'test-api' => fn () => ['fallback' => true],
        ],
    ]);

    $breaker = Fuse::store('array')->make('test-api');

    // Open circuit
    for ($i = 0; $i < 5; $i++) {
        try {
            $breaker->call(fn () => throw new \Exception());
        } catch (\Exception $e) {}
    }

    // Next call should throw exception with fallback
    try {
        $breaker->call(fn () => ['real' => true]);
        $this->fail('Should have thrown CircuitBreakerOpenException');
    } catch (CircuitBreakerOpenException $e) {
        $this->assertTrue($e->hasFallback());
        $this->assertEquals(['fallback' => true], $e->fallbackValue);
    }
}
```

### Test No Fallback

```php
public function test_exception_without_fallback()
{
    config(['fuse.fallbacks.enabled' => false]);

    $breaker = Fuse::store('array')->make('test-api');

    // Open circuit
    for ($i = 0; $i < 5; $i++) {
        try {
            $breaker->call(fn () => throw new \Exception());
        } catch (\Exception $e) {}
    }

    // Should throw without fallback
    $this->expectException(CircuitBreakerOpenException::class);

    $breaker->call(fn () => ['real' => true]);
}
```

## Testing Different Strategies

### Test Consecutive Failures Strategy

```php
public function test_consecutive_failures_strategy()
{
    $breaker = Fuse::store('array')
        ->make('test', strategyName: 'consecutive_failures');

    // Fail, succeed, fail pattern
    $this->simulateFailure($breaker);
    $this->simulateSuccess($breaker);
    $this->simulateFailure($breaker);

    // Should still be closed (consecutive counter reset)
    $this->assertTrue($breaker->getState()->isClosed());

    // Now fail 5 times consecutively
    for ($i = 0; $i < 5; $i++) {
        $this->simulateFailure($breaker);
    }

    // Should be open
    $this->assertTrue($breaker->getState()->isOpen());
}

private function simulateFailure($breaker): void
{
    try {
        $breaker->call(fn () => throw new \Exception());
    } catch (\Exception $e) {}
}

private function simulateSuccess($breaker): void
{
    $breaker->call(fn () => true);
}
```

### Test Percentage Failures Strategy

```php
public function test_percentage_failures_strategy()
{
    $config = CircuitBreakerConfiguration::fromDefaults('test')
        ->withPercentageThreshold(50)
        ->withMinimumThroughput(10);

    $breaker = Fuse::store('array')
        ->make('test', configuration: $config, strategyName: 'percentage_failures');

    // 6 failures, 4 successes = 60% failure rate
    for ($i = 0; $i < 6; $i++) {
        $this->simulateFailure($breaker);
    }

    for ($i = 0; $i < 4; $i++) {
        $this->simulateSuccess($breaker);
    }

    // Should be open (60% > 50% threshold)
    $this->assertTrue($breaker->getState()->isOpen());
}
```

### Test Rolling Window Strategy

```php
public function test_rolling_window_strategy()
{
    $config = CircuitBreakerConfiguration::fromDefaults('test')
        ->withSamplingDuration(60) // 60-second window
        ->withPercentageThreshold(50)
        ->withMinimumThroughput(5);

    $breaker = Fuse::store('array')
        ->make('test', configuration: $config, strategyName: 'rolling_window');

    // Record failures and successes
    for ($i = 0; $i < 6; $i++) {
        $this->simulateFailure($breaker);
    }

    for ($i = 0; $i < 2; $i++) {
        $this->simulateSuccess($breaker);
    }

    // 6 failures + 2 successes = 75% failure rate
    $this->assertTrue($breaker->getState()->isOpen());
}
```

## Testing Exception Handling

### Test Ignored Exceptions

```php
public function test_ignored_exceptions_dont_trigger_failures()
{
    config([
        'fuse.exceptions.ignore' => [
            \Illuminate\Validation\ValidationException::class,
        ],
    ]);

    $breaker = Fuse::store('array')->make('test');

    // Throw ignored exceptions 10 times
    for ($i = 0; $i < 10; $i++) {
        try {
            $breaker->call(function () {
                throw new \Illuminate\Validation\ValidationException(
                    validator([], [])
                );
            });
        } catch (\Exception $e) {}
    }

    // Circuit should still be closed
    $this->assertTrue($breaker->getState()->isClosed());
}
```

### Test Recorded Exceptions

```php
public function test_only_recorded_exceptions_trigger_failures()
{
    config([
        'fuse.exceptions.record' => [
            \Illuminate\Http\Client\ConnectionException::class,
        ],
    ]);

    $breaker = Fuse::store('array')->make('test');

    // Throw non-recorded exception
    for ($i = 0; $i < 10; $i++) {
        try {
            $breaker->call(fn () => throw new \RuntimeException());
        } catch (\Exception $e) {}
    }

    // Should still be closed (not a recorded exception)
    $this->assertTrue($breaker->getState()->isClosed());

    // Throw recorded exception 5 times
    for ($i = 0; $i < 5; $i++) {
        try {
            $breaker->call(function () {
                throw new \Illuminate\Http\Client\ConnectionException();
            });
        } catch (\Exception $e) {}
    }

    // Should now be open
    $this->assertTrue($breaker->getState()->isOpen());
}
```

## Testing Events

### Test Event Dispatching

```php
public function test_circuit_opened_event_is_dispatched()
{
    Event::fake([CircuitBreakerOpened::class]);

    $breaker = Fuse::store('array')->make('test');

    // Open circuit
    for ($i = 0; $i < 5; $i++) {
        try {
            $breaker->call(fn () => throw new \Exception());
        } catch (\Exception $e) {}
    }

    // Assert event was dispatched
    Event::assertDispatched(CircuitBreakerOpened::class, function ($event) {
        return $event->name === 'test';
    });
}
```

### Test Event Listeners

```php
public function test_alert_is_sent_when_circuit_opens()
{
    Notification::fake();

    Event::listen(CircuitBreakerOpened::class, function ($event) {
        Notification::send(
            User::admins()->get(),
            new CircuitBreakerAlert($event->name)
        );
    });

    $breaker = Fuse::store('array')->make('test');

    // Open circuit
    for ($i = 0; $i < 5; $i++) {
        try {
            $breaker->call(fn () => throw new \Exception());
        } catch (\Exception $e) {}
    }

    // Assert notification was sent
    Notification::assertSentTo(
        User::admins()->get(),
        CircuitBreakerAlert::class
    );
}
```

## Testing Metrics

### Test Metric Tracking

```php
public function test_metrics_are_tracked_correctly()
{
    $breaker = Fuse::store('array')->make('test');

    // Record some failures and successes
    for ($i = 0; $i < 3; $i++) {
        $this->simulateFailure($breaker);
    }

    for ($i = 0; $i < 2; $i++) {
        $this->simulateSuccess($breaker);
    }

    $metrics = $breaker->getMetrics();

    $this->assertEquals(3, $metrics->totalFailures);
    $this->assertEquals(2, $metrics->totalSuccesses);
    $this->assertEquals(60, $metrics->failureRate()); // 3/(3+2) = 60%
}
```

### Test Failure Rate Calculation

```php
public function test_failure_rate_calculation()
{
    $breaker = Fuse::store('array')->make('test');

    // 7 failures, 3 successes
    for ($i = 0; $i < 7; $i++) {
        $this->simulateFailure($breaker);
    }

    for ($i = 0; $i < 3; $i++) {
        $this->simulateSuccess($breaker);
    }

    $metrics = $breaker->getMetrics();

    $this->assertEquals(70, $metrics->failureRate()); // 7/(7+3) = 70%
}
```

## Testing with HTTP Calls

### Mock HTTP Responses

```php
use Illuminate\Support\Facades\Http;

public function test_circuit_breaker_with_http_client()
{
    // Mock successful response
    Http::fake([
        'api.example.com/*' => Http::response(['data' => 'success'], 200),
    ]);

    $breaker = Fuse::store('array')->make('api');

    $result = $breaker->call(function () {
        return Http::get('https://api.example.com/data')->json();
    });

    $this->assertEquals(['data' => 'success'], $result);
}

public function test_circuit_breaker_with_http_failures()
{
    // Mock failed responses
    Http::fake([
        'api.example.com/*' => Http::response([], 500),
    ]);

    $breaker = Fuse::store('array')->make('api');

    // Simulate failures
    for ($i = 0; $i < 5; $i++) {
        try {
            $breaker->call(function () {
                Http::get('https://api.example.com/data')->throw();
            });
        } catch (\Exception $e) {}
    }

    $this->assertTrue($breaker->getState()->isOpen());
}
```

## Testing Database Operations

### Mock Database Failures

```php
use Illuminate\Support\Facades\DB;

public function test_circuit_breaker_with_database_failures()
{
    DB::shouldReceive('connection')
        ->andThrow(new \PDOException('Connection refused'));

    $breaker = Fuse::store('array')->make('db');

    for ($i = 0; $i < 5; $i++) {
        try {
            $breaker->call(function () {
                DB::connection('analytics')->select('SELECT 1');
            });
        } catch (\Exception $e) {}
    }

    $this->assertTrue($breaker->getState()->isOpen());
}
```

## Testing Custom Strategies

### Test Custom Strategy Logic

```php
public function test_custom_time_of_day_strategy()
{
    // Register custom strategy
    Fuse::extend('time_of_day', fn () => new TimeOfDayStrategy());

    $breaker = Fuse::store('array')
        ->make('test', strategyName: 'time_of_day');

    // Test behavior based on time
    // (You might need to mock time or test at specific times)
}
```

## Integration Testing

### Test Real Service Integration

```php
/**
 * @group integration
 */
public function test_real_api_with_circuit_breaker()
{
    $this->markTestSkipped('Only run with real API access');

    $breaker = Fuse::store('cache')->make('real-api');

    $result = $breaker->call(function () {
        return Http::get('https://real-api.example.com/data')->json();
    });

    $this->assertNotEmpty($result);
}
```

## Test Helpers

### Create Reusable Test Helpers

```php
trait CircuitBreakerTestHelpers
{
    protected function forceCircuitOpen(string $name, int $failures = 5): void
    {
        $breaker = Fuse::store('array')->make($name);

        for ($i = 0; $i < $failures; $i++) {
            try {
                $breaker->call(fn () => throw new \Exception('Test failure'));
            } catch (\Exception $e) {}
        }
    }

    protected function forceCircuitClosed(string $name): void
    {
        Fuse::store('array')->make($name)->reset();
    }

    protected function assertCircuitOpen(string $name): void
    {
        $this->assertTrue(
            Fuse::store('array')->make($name)->getState()->isOpen(),
            "Circuit '{$name}' is not open"
        );
    }

    protected function assertCircuitClosed(string $name): void
    {
        $this->assertTrue(
            Fuse::store('array')->make($name)->getState()->isClosed(),
            "Circuit '{$name}' is not closed"
        );
    }

    protected function getCircuitMetrics(string $name): CircuitBreakerMetrics
    {
        return Fuse::store('array')->make($name)->getMetrics();
    }
}
```

Usage:

```php
class ServiceTest extends TestCase
{
    use CircuitBreakerTestHelpers;

    public function test_service_handles_circuit_breaker()
    {
        $this->forceCircuitOpen('api');

        $result = $this->service->callApi();

        $this->assertNull($result);
    }
}
```

## Mocking Circuit Breakers

### Mock for Unit Tests

```php
public function test_service_without_circuit_breaker_behavior()
{
    // Mock the circuit breaker to always succeed
    $mockBreaker = Mockery::mock(CircuitBreaker::class);
    $mockBreaker->shouldReceive('call')
        ->once()
        ->andReturn(['data' => 'test']);

    $this->app->instance('cb.api', $mockBreaker);

    // Test your service logic without circuit breaker behavior
}
```

## Testing Performance

### Benchmark Circuit Breaker Overhead

```php
public function test_circuit_breaker_performance()
{
    $breaker = Fuse::store('array')->make('perf-test');

    $start = microtime(true);

    for ($i = 0; $i < 1000; $i++) {
        $breaker->call(fn () => true);
    }

    $elapsed = microtime(true) - $start;

    // Assert reasonable performance (adjust threshold as needed)
    $this->assertLessThan(1.0, $elapsed, 'Circuit breaker too slow');
}
```

## Best Practices

1. **Use Array Driver for Tests**
   - Fast, isolated, no cleanup needed
   - Each test starts fresh

2. **Disable Events**
   - Speeds up tests
   - Reduces side effects
   - Enable only when testing events

3. **Test All States**
   - Closed state behavior
   - Open state behavior
   - Half-open state behavior
   - State transitions

4. **Test Edge Cases**
   - Exactly at threshold
   - Just below threshold
   - Just above threshold
   - Boundary conditions

5. **Use Test Helpers**
   - Reduce duplication
   - Consistent test setup
   - Easier maintenance

6. **Mock External Services**
   - Don't rely on real APIs in tests
   - Faster test execution
   - Deterministic results

## Complete Test Example

```php
namespace Tests\Feature;

use Tests\TestCase;
use Cline\Fuse\Facades\Fuse;
use Cline\Fuse\Exceptions\CircuitBreakerOpenException;
use Illuminate\Support\Facades\Http;

class ApiServiceTest extends TestCase
{
    use CircuitBreakerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fuse.default' => 'array',
            'fuse.events.enabled' => false,
        ]);
    }

    public function test_successful_api_call()
    {
        Http::fake(['api.example.com/*' => Http::response(['success' => true])]);

        $service = new ApiService();
        $result = $service->fetchData();

        $this->assertEquals(['success' => true], $result);
        $this->assertCircuitClosed('api');
    }

    public function test_circuit_opens_after_failures()
    {
        Http::fake(['api.example.com/*' => Http::response([], 500)]);

        $service = new ApiService();

        // Call service 5 times to open circuit
        for ($i = 0; $i < 5; $i++) {
            try {
                $service->fetchData();
            } catch (\Exception $e) {}
        }

        $this->assertCircuitOpen('api');
    }

    public function test_fallback_is_used_when_circuit_open()
    {
        config([
            'fuse.fallbacks.enabled' => true,
            'fuse.fallbacks.handlers.api' => fn () => ['fallback' => true],
        ]);

        $this->forceCircuitOpen('api');

        $service = new ApiService();

        try {
            $result = $service->fetchData();
        } catch (CircuitBreakerOpenException $e) {
            $this->assertTrue($e->hasFallback());
            $this->assertEquals(['fallback' => true], $e->fallbackValue);
        }
    }
}
```

## Next Steps

- **[Use Cases](use-cases.md)** - Real-world testing scenarios
- **[Advanced Usage](advanced-usage.md)** - Test advanced patterns
- **[Monitoring & Events](monitoring-and-events.md)** - Test event integrations
