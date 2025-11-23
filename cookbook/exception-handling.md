# Exception Handling

Fuse provides fine-grained control over which exceptions trigger circuit breaker failures. Not all exceptions represent service failures - some indicate client errors that shouldn't open circuits.

## The Problem

By default, any exception thrown during a circuit breaker call counts as a failure:

```php
Fuse::make('api')->call(function () {
    // Validation error - client's fault, not service failure
    throw new ValidationException('Invalid input');
    // ❌ Circuit breaker counts this as a failure
});
```

This can lead to circuits opening for the wrong reasons.

## Exception Configuration

Control exception behavior in `config/fuse.php`:

```php
'exceptions' => [
    'ignore' => [
        // Exceptions that won't be counted as failures
    ],
    'record' => [
        // Only these exceptions count as failures (whitelist)
    ],
],
```

## Ignoring Exceptions

### Blacklist Approach

List exceptions that should NOT trigger failures:

```php
'exceptions' => [
    'ignore' => [
        \Illuminate\Validation\ValidationException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\AuthorizationException::class,
    ],
],
```

Now these exceptions pass through without counting as failures:

```php
Fuse::make('api')->call(function () {
    // These won't trigger circuit breaker failures
    throw new ValidationException(); // ✅ Ignored
    throw new NotFoundHttpException(); // ✅ Ignored
});
```

### Client vs Server Errors

Ignore client errors (4xx) but record server errors (5xx):

```php
'exceptions' => [
    'ignore' => [
        // 400 Bad Request
        \Illuminate\Validation\ValidationException::class,

        // 401 Unauthorized
        \Illuminate\Auth\AuthenticationException::class,

        // 403 Forbidden
        \Illuminate\Auth\AuthorizationException::class,

        // 404 Not Found
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,

        // 422 Unprocessable Entity
        \Illuminate\Validation\ValidationException::class,
    ],
],
```

### Business Logic Exceptions

Ignore expected business exceptions:

```php
'exceptions' => [
    'ignore' => [
        \App\Exceptions\InsufficientFundsException::class,
        \App\Exceptions\ProductOutOfStockException::class,
        \App\Exceptions\InvalidCouponException::class,
    ],
],
```

## Recording Specific Exceptions

### Whitelist Approach

Only count specific exceptions as failures:

```php
'exceptions' => [
    'record' => [
        // Only connection/network failures count
        \Illuminate\Http\Client\ConnectionException::class,
        \GuzzleHttp\Exception\ConnectException::class,
        \Illuminate\Http\Client\RequestException::class,
    ],
],
```

When `record` is not empty, ONLY these exceptions trigger failures:

```php
Fuse::make('api')->call(function () {
    throw new ConnectionException(); // ✅ Counted as failure
    throw new ValidationException(); // ❌ Not counted (not in whitelist)
});
```

### Network Failures Only

Track only network-related issues:

```php
'exceptions' => [
    'record' => [
        \Illuminate\Http\Client\ConnectionException::class,
        \GuzzleHttp\Exception\ConnectException::class,
        \GuzzleHttp\Exception\RequestException::class,
        \Symfony\Component\HttpClient\Exception\TransportException::class,
    ],
],
```

### Database Failures Only

Track only database issues:

```php
'exceptions' => [
    'record' => [
        \Illuminate\Database\QueryException::class,
        \PDOException::class,
    ],
],
```

## Combining Ignore and Record

**Important:** When both are configured:
1. First checks `record` list (whitelist)
2. Then checks `ignore` list (blacklist)
3. If `record` is empty, all exceptions count except those in `ignore`

```php
'exceptions' => [
    'ignore' => [
        \ValidationException::class,  // Always ignored
    ],
    'record' => [
        \ConnectionException::class,   // Only this counts
        \TimeoutException::class,      // And this
    ],
],
```

## Use Cases

### HTTP API Calls

```php
'exceptions' => [
    'ignore' => [
        // Client errors (4xx)
        \Illuminate\Http\Client\RequestException::class, // When specifically 4xx
    ],
    'record' => [
        // Server errors and connectivity issues
        \Illuminate\Http\Client\ConnectionException::class,
        \GuzzleHttp\Exception\ConnectException::class,
        \GuzzleHttp\Exception\ServerException::class, // 5xx errors
    ],
],
```

### Database Operations

```php
'exceptions' => [
    'record' => [
        // Connection and timeout issues
        \Illuminate\Database\QueryException::class,
        \PDOException::class,
    ],
    'ignore' => [
        // Data integrity issues are client problems
        \Illuminate\Database\UniqueConstraintViolationException::class,
        \Illuminate\Database\ForeignKeyConstraintViolationException::class,
    ],
],
```

### Payment Processing

```php
'exceptions' => [
    'record' => [
        // Service unavailability
        \Stripe\Exception\ApiConnectionException::class,
        \Stripe\Exception\RateLimitException::class,
    ],
    'ignore' => [
        // Business rule violations
        \Stripe\Exception\CardException::class, // Declined card
        \Stripe\Exception\InvalidRequestException::class, // Bad params
    ],
],
```

### Third-Party SDK

```php
'exceptions' => [
    'record' => [
        // Service failures
        \OpenAI\Exceptions\TransporterException::class,
        \OpenAI\Exceptions\TimeoutException::class,
    ],
    'ignore' => [
        // Usage errors
        \OpenAI\Exceptions\UnserializableResponse::class,
        \OpenAI\Exceptions\InvalidRequestException::class,
    ],
],
```

## Custom Exception Handling

### Check Exception Before Recording

You can check exceptions in your code:

```php
use Cline\Fuse\Facades\Fuse;
use Cline\Fuse\Exceptions\CircuitBreakerOpenException;

try {
    $result = Fuse::make('api')->call(function () {
        return Http::get('https://api.example.com/data')->throw()->json();
    });
} catch (CircuitBreakerOpenException $e) {
    // Circuit is open
    return $this->handleOpenCircuit($e);
} catch (ValidationException $e) {
    // Validation error - not recorded by circuit breaker
    return response()->json(['errors' => $e->errors()], 422);
} catch (ConnectionException $e) {
    // Connection error - recorded by circuit breaker
    Log::error("API connection failed", ['exception' => $e]);
    throw $e;
}
```

### Conditional Recording

Wrap with your own logic:

```php
public function callApiWithCircuitBreaker($data)
{
    try {
        return Fuse::make('api')->call(function () use ($data) {
            return $this->api->call($data);
        });
    } catch (\Exception $e) {
        // Custom logic to determine if it should count
        if ($this->isTransientError($e)) {
            // Let circuit breaker handle it
            throw $e;
        }

        // Don't let circuit breaker see it
        Log::warning("API error (not circuit breaker)", ['exception' => $e]);
        return null;
    }
}

private function isTransientError(\Exception $e): bool
{
    return $e instanceof ConnectionException
        || $e instanceof TimeoutException
        || ($e instanceof RequestException && $e->response->serverError());
}
```

## HTTP Status Code Filtering

### Ignore 4xx, Record 5xx

```php
Fuse::make('api')->call(function () {
    try {
        return Http::get('https://api.example.com/data')
            ->throw(function ($response, $e) {
                // Only throw on 5xx errors
                return $response->serverError();
            })
            ->json();
    } catch (RequestException $e) {
        if ($e->response->clientError()) {
            // 4xx - client error, return null without throwing
            return null;
        }
        // 5xx - server error, throw to trigger circuit breaker
        throw $e;
    }
});
```

### Custom HTTP Handling

```php
'exceptions' => [
    'record' => [
        \App\Exceptions\ServiceUnavailableException::class,
    ],
],

// In your code
Fuse::make('api')->call(function () {
    $response = Http::get('https://api.example.com/data');

    if ($response->status() >= 500) {
        throw new ServiceUnavailableException("API returned {$response->status()}");
    }

    if ($response->status() >= 400) {
        // Client error - don't trigger circuit breaker
        return null;
    }

    return $response->json();
});
```

## Per-Circuit Exception Configuration

While global configuration is in `config/fuse.php`, you can override per circuit:

```php
// Service provider or bootstrap
use Cline\Fuse\Facades\Fuse;

class CircuitBreakerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Configure payment gateway circuit with specific exception handling
        $this->app->singleton('cb.payment', function () {
            return Fuse::make('payment-gateway');
        });

        // The global config still applies, but you can wrap with custom logic
    }
}
```

## Testing Exception Handling

### Test Ignored Exceptions

```php
use Tests\TestCase;
use Cline\Fuse\Facades\Fuse;
use Illuminate\Validation\ValidationException;

class ExceptionHandlingTest extends TestCase
{
    public function test_validation_exception_does_not_open_circuit()
    {
        config(['fuse.exceptions.ignore' => [ValidationException::class]]);

        $breaker = Fuse::store('array')->make('test');

        // Throw validation errors 10 times
        for ($i = 0; $i < 10; $i++) {
            try {
                $breaker->call(fn () => throw new ValidationException(validator([], [])));
            } catch (ValidationException $e) {
                // Expected
            }
        }

        // Circuit should still be closed
        $this->assertTrue($breaker->getState()->isClosed());
    }
}
```

### Test Recorded Exceptions

```php
public function test_connection_exception_opens_circuit()
{
    config(['fuse.exceptions.record' => [ConnectionException::class]]);

    $breaker = Fuse::store('array')->make('test');

    // Throw connection errors 5 times
    for ($i = 0; $i < 5; $i++) {
        try {
            $breaker->call(fn () => throw new ConnectionException('Failed'));
        } catch (ConnectionException $e) {
            // Expected
        }
    }

    // Circuit should be open
    $this->assertTrue($breaker->getState()->isOpen());
}
```

## Real-World Examples

### E-commerce API

```php
'exceptions' => [
    'ignore' => [
        // User errors
        \App\Exceptions\InvalidProductException::class,
        \App\Exceptions\OutOfStockException::class,
        \App\Exceptions\InvalidCouponException::class,

        // Business rule violations
        \Illuminate\Validation\ValidationException::class,
    ],
    'record' => [
        // Service failures
        \Illuminate\Http\Client\ConnectionException::class,
        \GuzzleHttp\Exception\ServerException::class,
    ],
],
```

### Microservice Communication

```php
'exceptions' => [
    'record' => [
        // Network failures
        \Illuminate\Http\Client\ConnectionException::class,
        \GuzzleHttp\Exception\ConnectException::class,

        // Service errors (5xx)
        \GuzzleHttp\Exception\ServerException::class,

        // Timeouts
        \Illuminate\Http\Client\RequestException::class,
    ],
    'ignore' => [
        // Client errors (4xx)
        \GuzzleHttp\Exception\ClientException::class,
    ],
],
```

### Database with Retry Logic

```php
use Illuminate\Support\Facades\DB;

Fuse::make('reporting-db')->call(function () {
    try {
        return DB::connection('reporting')->transaction(function () {
            return $this->generateReport();
        });
    } catch (\PDOException $e) {
        // Check if it's a connection error
        if ($e->getCode() === 'HY000' || str_contains($e->getMessage(), 'gone away')) {
            // Connection error - let circuit breaker handle it
            throw $e;
        }

        // Data/query error - don't trigger circuit breaker
        Log::error("Query error (not circuit breaker)", ['exception' => $e]);
        return null;
    }
});
```

## Best Practices

1. **Start Broad, Narrow Down**
   - Begin with no filters
   - Monitor what causes opens
   - Add ignored exceptions based on real data

2. **Client vs Server**
   - Always ignore client errors (4xx, validation)
   - Always record server errors (5xx, connectivity)

3. **Business Logic**
   - Ignore expected business exceptions
   - Record infrastructure failures

4. **Document Decisions**
   - Comment why exceptions are ignored/recorded
   - Review exception configuration regularly

5. **Test Thoroughly**
   - Test both ignored and recorded exceptions
   - Verify circuit behavior under different failures

## Environment-Specific Configuration

### Development

```php
'exceptions' => [
    // Be lenient in development
    'ignore' => [
        \ValidationException::class,
        \NotFoundHttpException::class,
    ],
],
```

### Testing

```php
'exceptions' => [
    // Strict in tests
    'record' => [
        \ConnectionException::class,
        \TimeoutException::class,
    ],
],
```

### Production

```php
'exceptions' => [
    // Fine-tuned based on monitoring
    'ignore' => [
        \ValidationException::class,
        \NotFoundHttpException::class,
        \AuthenticationException::class,
        \App\Exceptions\BusinessRuleException::class,
    ],
    'record' => [
        \ConnectionException::class,
        \TimeoutException::class,
        \ServerException::class,
    ],
],
```

## Next Steps

- **[Monitoring & Events](monitoring-and-events.md)** - Track exception patterns
- **[Testing](testing.md)** - Test exception handling
- **[Use Cases](use-cases.md)** - More real-world exception scenarios
