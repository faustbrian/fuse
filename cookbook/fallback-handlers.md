# Fallback Handlers

When a circuit breaker opens, you have two choices: throw an exception or provide a fallback response. Fallback handlers enable graceful degradation by returning alternative values when services fail.

## Why Use Fallbacks?

**Without Fallbacks:**
```php
try {
    $data = Fuse::make('api')->call($callable);
} catch (CircuitBreakerOpenException $e) {
    // Circuit is open - now what?
    return response()->json(['error' => 'Service unavailable'], 503);
}
```

**With Fallbacks:**
```php
try {
    $data = Fuse::make('api')->call($callable);
} catch (CircuitBreakerOpenException $e) {
    if ($e->hasFallback()) {
        return $e->fallbackValue; // Automatically handled
    }
}
```

## Configuration

### Enable Fallbacks

In `config/fuse.php`:

```php
'fallbacks' => [
    'enabled' => env('FUSE_FALLBACKS_ENABLED', true),
],
```

```env
FUSE_FALLBACKS_ENABLED=true
```

### Global Default Fallback

Applies to all circuit breakers without specific handlers:

```php
'fallbacks' => [
    'default' => fn ($name) => [
        'status' => 'unavailable',
        'service' => $name,
        'fallback' => true,
    ],
],
```

### Service-Specific Fallbacks

Define fallbacks for individual services:

```php
'fallbacks' => [
    'handlers' => [
        'user-api' => fn () => Cache::get('users-cached', []),
        'weather-api' => fn () => ['temp' => null, 'cached' => true],
        'payment-gateway' => fn () => throw new ServiceUnavailableException(),
    ],
],
```

## Basic Usage

### Handling Fallback Values

```php
use Cline\Fuse\Facades\Fuse;
use Cline\Fuse\Exceptions\CircuitBreakerOpenException;

try {
    $result = Fuse::make('external-api')->call(function () {
        return Http::get('https://api.example.com/data')->json();
    });
} catch (CircuitBreakerOpenException $e) {
    if ($e->hasFallback()) {
        // Use the configured fallback
        $result = $e->fallbackValue;
        Log::info("Using fallback for {$e->name}");
    } else {
        // No fallback configured
        throw $e;
    }
}

return $result;
```

### Checking for Fallbacks

```php
try {
    $data = Fuse::make('api')->call($callable);
} catch (CircuitBreakerOpenException $e) {
    if (!$e->hasFallback()) {
        // Handle the case where no fallback exists
        return response()->json([
            'error' => 'Service unavailable',
            'circuit' => $e->name,
        ], 503);
    }

    // Fallback exists
    return $e->fallbackValue;
}
```

## Fallback Strategies

### Static Data

Return predefined data:

```php
'fallbacks' => [
    'handlers' => [
        'weather-api' => fn () => [
            'temperature' => null,
            'conditions' => 'unknown',
            'timestamp' => now()->toIso8601String(),
        ],
    ],
],
```

### Cached Data

Return cached responses:

```php
'fallbacks' => [
    'handlers' => [
        'user-api' => function () {
            return Cache::remember('api-users-fallback', 3600, function () {
                return ['users' => []];
            });
        },
    ],
],
```

### Database Fallback

Query local database instead:

```php
'fallbacks' => [
    'handlers' => [
        'product-api' => function () {
            return DB::table('products_cache')
                ->where('updated_at', '>', now()->subHour())
                ->get();
        },
    ],
],
```

### Empty Response

Return empty but valid data:

```php
'fallbacks' => [
    'handlers' => [
        'recommendations-api' => fn () => [],
        'analytics-api' => fn () => ['events' => [], 'total' => 0],
    ],
],
```

### Throw Custom Exception

Re-throw with more context:

```php
'fallbacks' => [
    'handlers' => [
        'payment-gateway' => function () {
            throw new PaymentGatewayUnavailableException(
                'Payment gateway is temporarily unavailable. Please try again later.'
            );
        },
    ],
],
```

## Advanced Fallback Patterns

### Cascade to Secondary Service

Try a backup service:

```php
'fallbacks' => [
    'handlers' => [
        'primary-api' => function () {
            try {
                return Fuse::make('secondary-api')->call(function () {
                    return Http::get('https://backup-api.example.com/data')->json();
                });
            } catch (\Exception $e) {
                return Cache::get('api-data-fallback', []);
            }
        },
    ],
],
```

### Stale Cache Strategy

Return old cached data with warning:

```php
'fallbacks' => [
    'handlers' => [
        'product-api' => function () {
            $cached = Cache::get('products');

            if ($cached) {
                $cached['_stale'] = true;
                $cached['_cached_at'] = Cache::get('products_timestamp');
                return $cached;
            }

            return ['products' => [], '_empty' => true];
        },
    ],
],
```

### Partial Data Fallback

Return subset of data:

```php
'fallbacks' => [
    'handlers' => [
        'user-profile-api' => function () {
            // Return basic user info from database
            return [
                'id' => auth()->id(),
                'name' => auth()->user()->name,
                'email' => auth()->user()->email,
                // External API fields omitted
                '_partial' => true,
            ];
        },
    ],
],
```

### Queue for Later Processing

Schedule retry:

```php
'fallbacks' => [
    'handlers' => [
        'notification-api' => function () {
            // Queue notification for later
            SendNotificationJob::dispatch($data)
                ->delay(now()->addMinutes(5));

            return ['status' => 'queued'];
        },
    ],
],
```

## Dynamic Fallbacks

### Context-Aware Fallbacks

Use request context in fallback:

```php
class FallbackService
{
    public function userApiFallback()
    {
        $userId = request()->route('user');

        // Try to get from cache
        $cached = Cache::get("user:{$userId}");

        if ($cached) {
            return $cached;
        }

        // Try database
        return User::find($userId)?->toArray() ?? [];
    }
}

// In config
'fallbacks' => [
    'handlers' => [
        'user-api' => [app(FallbackService::class), 'userApiFallback'],
    ],
],
```

### Time-Based Fallbacks

Different fallbacks based on time:

```php
'fallbacks' => [
    'handlers' => [
        'analytics-api' => function () {
            $hour = now()->hour;

            // Business hours: return recent cache
            if ($hour >= 9 && $hour <= 17) {
                return Cache::get('analytics-recent') ?? [];
            }

            // Off-hours: return daily summary
            return Cache::get('analytics-daily') ?? [];
        },
    ],
],
```

### User-Specific Fallbacks

Different fallbacks for different users:

```php
'fallbacks' => [
    'handlers' => [
        'premium-api' => function () {
            $user = auth()->user();

            if ($user->isPremium()) {
                // Premium users get cached data
                return Cache::get('premium-data') ?? [];
            }

            // Free users get empty response
            return ['message' => 'Feature unavailable'];
        },
    ],
],
```

## Fallback Response Metadata

### Add Fallback Indicators

```php
'fallbacks' => [
    'handlers' => [
        'api' => function () {
            $data = Cache::get('api-fallback', []);

            return array_merge($data, [
                '_fallback' => true,
                '_timestamp' => now()->toIso8601String(),
                '_source' => 'cache',
            ]);
        },
    ],
],
```

### Track Fallback Usage

```php
'fallbacks' => [
    'handlers' => [
        'api' => function () {
            // Log fallback usage
            Log::warning('Circuit breaker fallback triggered', [
                'circuit' => 'api',
                'timestamp' => now(),
            ]);

            // Increment metrics
            Metrics::increment('circuit_breaker.fallback.used', [
                'service' => 'api',
            ]);

            return Cache::get('api-fallback', []);
        },
    ],
],
```

## Testing Fallbacks

### Test Fallback Execution

```php
use Tests\TestCase;
use Cline\Fuse\Facades\Fuse;
use Cline\Fuse\Exceptions\CircuitBreakerOpenException;

class FallbackTest extends TestCase
{
    public function test_fallback_is_used_when_circuit_is_open()
    {
        // Configure fallback
        config(['fuse.fallbacks.handlers.test-api' => fn () => ['fallback' => true]]);

        $breaker = Fuse::store('array')->make('test-api');

        // Force circuit open
        for ($i = 0; $i < 5; $i++) {
            try {
                $breaker->call(fn () => throw new \Exception('Fail'));
            } catch (\Exception $e) {}
        }

        // Next call should use fallback
        try {
            $result = $breaker->call(fn () => ['real' => true]);
            $this->fail('Should have thrown CircuitBreakerOpenException');
        } catch (CircuitBreakerOpenException $e) {
            $this->assertTrue($e->hasFallback());
            $this->assertEquals(['fallback' => true], $e->fallbackValue);
        }
    }
}
```

### Test Without Fallback

```php
public function test_exception_thrown_when_no_fallback()
{
    config(['fuse.fallbacks.enabled' => false]);

    $breaker = Fuse::store('array')->make('test-api');

    // Force circuit open
    for ($i = 0; $i < 5; $i++) {
        try {
            $breaker->call(fn () => throw new \Exception('Fail'));
        } catch (\Exception $e) {}
    }

    // Should throw exception without fallback
    $this->expectException(CircuitBreakerOpenException::class);

    $breaker->call(fn () => ['real' => true]);
}
```

## Real-World Examples

### E-commerce Product API

```php
'fallbacks' => [
    'handlers' => [
        'product-api' => function () {
            // Try database cache table
            $products = DB::table('products_cache')
                ->where('updated_at', '>', now()->subHours(2))
                ->get();

            if ($products->isNotEmpty()) {
                return [
                    'products' => $products,
                    '_source' => 'cache',
                    '_stale' => true,
                ];
            }

            // Return empty with message
            return [
                'products' => [],
                'message' => 'Products temporarily unavailable',
            ];
        },
    ],
],
```

### User Authentication Service

```php
'fallbacks' => [
    'handlers' => [
        'auth-service' => function () {
            // Check local user table
            $user = User::where('email', request('email'))->first();

            if ($user && Hash::check(request('password'), $user->password)) {
                return [
                    'authenticated' => true,
                    'user' => $user,
                    '_local_auth' => true,
                ];
            }

            throw new AuthenticationException('Authentication service unavailable');
        },
    ],
],
```

### Weather API

```php
'fallbacks' => [
    'handlers' => [
        'weather-api' => function () {
            // Get last known weather from cache
            $lastKnown = Cache::get('weather-last-known');

            if ($lastKnown) {
                return array_merge($lastKnown, [
                    '_cached' => true,
                    '_age' => now()->diffInMinutes($lastKnown['timestamp']),
                ]);
            }

            // Return default
            return [
                'temperature' => null,
                'conditions' => 'unknown',
                'available' => false,
            ];
        },
    ],
],
```

### Payment Gateway

```php
'fallbacks' => [
    'handlers' => [
        'payment-gateway' => function () {
            // For critical services like payments, don't silently fail
            // Log the issue
            Log::critical('Payment gateway circuit breaker opened', [
                'timestamp' => now(),
                'user' => auth()->id(),
            ]);

            // Alert team
            AlertService::send('Payment gateway is down');

            // Throw specific exception
            throw new PaymentGatewayUnavailableException(
                'Our payment system is temporarily unavailable. Please try again in a few minutes.'
            );
        },
    ],
],
```

### Analytics/Tracking Service

```php
'fallbacks' => [
    'handlers' => [
        'analytics-api' => function () {
            // Analytics not critical - just log locally
            DB::table('analytics_queue')->insert([
                'event' => request('event'),
                'data' => json_encode(request('data')),
                'created_at' => now(),
            ]);

            return ['queued' => true];
        },
    ],
],
```

## Best Practices

1. **Critical vs Non-Critical**
   - Critical services: throw exceptions, don't hide failures
   - Non-critical: provide fallbacks for graceful degradation

2. **Fallback Data Quality**
   - Indicate when using fallback data
   - Include timestamp/age information
   - Mark stale or incomplete data

3. **Monitoring**
   - Log fallback usage
   - Alert on repeated fallbacks
   - Track fallback frequency

4. **Cache Strategy**
   - Keep recent successful responses cached
   - Set appropriate cache TTL
   - Consider separate cache for fallbacks

5. **Testing**
   - Test fallback code paths
   - Verify fallback data structure
   - Test with and without fallbacks enabled

## Next Steps

- **[Monitoring & Events](monitoring-and-events.md)** - Track fallback usage
- **[Exception Handling](exception-handling.md)** - Fine-tune exception behavior
- **[Testing](testing.md)** - Test circuit breaker fallbacks
- **[Use Cases](use-cases.md)** - More real-world examples
