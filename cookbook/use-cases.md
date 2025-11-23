# Use Cases

Real-world examples and patterns for using Fuse circuit breakers in production applications.

## External API Integration

### Weather API Service

```php
namespace App\Services;

use Cline\Fuse\Facades\Fuse;
use Cline\Fuse\Exceptions\CircuitBreakerOpenException;
use Cline\Fuse\ValueObjects\CircuitBreakerConfiguration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class WeatherService
{
    private const CACHE_TTL = 3600; // 1 hour

    public function getCurrentWeather(string $location): array
    {
        $config = CircuitBreakerConfiguration::fromDefaults('weather-api')
            ->withFailureThreshold(5)
            ->withTimeout(60);

        try {
            return Fuse::make('weather-api', configuration: $config)->call(function () use ($location) {
                $response = Http::timeout(5)
                    ->get('https://api.weather.com/current', [
                        'location' => $location,
                        'api_key' => config('services.weather.key'),
                    ])
                    ->throw()
                    ->json();

                // Cache successful response
                Cache::put("weather:{$location}", $response, self::CACHE_TTL);

                return $response;
            });
        } catch (CircuitBreakerOpenException $e) {
            // Return cached weather or default
            return Cache::get("weather:{$location}", [
                'temperature' => null,
                'conditions' => 'unavailable',
                'cached' => true,
            ]);
        }
    }
}
```

### Third-Party Payment Gateway

```php
namespace App\Services;

use Cline\Fuse\Facades\Fuse;
use Cline\Fuse\Exceptions\CircuitBreakerOpenException;
use Stripe\StripeClient;
use Stripe\Exception\ApiConnectionException;

class PaymentService
{
    public function processPayment(int $amount, string $token): array
    {
        $config = CircuitBreakerConfiguration::fromDefaults('stripe')
            ->withFailureThreshold(3)  // Very sensitive
            ->withSuccessThreshold(5)  // Cautious recovery
            ->withTimeout(180);        // 3 minutes

        try {
            return Fuse::make('stripe', configuration: $config)->call(function () use ($amount, $token) {
                $stripe = new StripeClient(config('services.stripe.secret'));

                return $stripe->charges->create([
                    'amount' => $amount,
                    'currency' => 'usd',
                    'source' => $token,
                ]);
            });
        } catch (CircuitBreakerOpenException $e) {
            // Payment gateway down - critical error
            Log::critical('Stripe circuit breaker opened', [
                'amount' => $amount,
                'timestamp' => now(),
            ]);

            // Alert team immediately
            Alert::critical('Payment gateway unavailable');

            throw new PaymentGatewayUnavailableException(
                'Payment processing is temporarily unavailable. Please try again in a few minutes.'
            );
        }
    }
}
```

### Social Media API Integration

```php
namespace App\Services;

class SocialMediaService
{
    public function postToTwitter(string $message): ?array
    {
        $config = CircuitBreakerConfiguration::fromDefaults('twitter-api')
            ->withFailureThreshold(10)  // Tolerant (non-critical)
            ->withTimeout(30);

        try {
            return Fuse::make('twitter-api', configuration: $config)->call(function () use ($message) {
                return Http::withToken(config('services.twitter.bearer'))
                    ->post('https://api.twitter.com/2/tweets', [
                        'text' => $message,
                    ])
                    ->throw()
                    ->json();
            });
        } catch (CircuitBreakerOpenException $e) {
            // Social media not critical - queue for later
            PostToTwitterJob::dispatch($message)->delay(now()->addMinutes(5));

            Log::info('Twitter post queued due to circuit breaker', [
                'message' => $message,
            ]);

            return null;
        }
    }
}
```

## Database Operations

### Analytics Database

```php
namespace App\Services;

class AnalyticsService
{
    public function getMonthlyReport(): array
    {
        $config = CircuitBreakerConfiguration::fromDefaults('analytics-db')
            ->withFailureThreshold(5)
            ->withTimeout(60)
            ->withStrategy('percentage_failures');

        try {
            return Fuse::make('analytics-db', configuration: $config)->call(function () {
                return DB::connection('analytics')
                    ->table('events')
                    ->where('created_at', '>', now()->subMonth())
                    ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
                    ->groupBy('date')
                    ->get()
                    ->toArray();
            });
        } catch (CircuitBreakerOpenException $e) {
            // Return cached report
            return Cache::get('analytics:monthly', []);
        }
    }

    public function trackEvent(string $event, array $data): void
    {
        try {
            Fuse::make('analytics-db')->call(function () use ($event, $data) {
                DB::connection('analytics')->table('events')->insert([
                    'event' => $event,
                    'data' => json_encode($data),
                    'created_at' => now(),
                ]);
            });
        } catch (CircuitBreakerOpenException $e) {
            // Queue event tracking for later
            DB::table('events_queue')->insert([
                'event' => $event,
                'data' => json_encode($data),
                'created_at' => now(),
            ]);
        }
    }
}
```

### Read Replica

```php
namespace App\Services;

class UserReportService
{
    public function generateReport(int $userId): array
    {
        try {
            // Try read replica first
            return Fuse::make('read-replica')->call(function () use ($userId) {
                return DB::connection('read')->table('users')
                    ->join('orders', 'users.id', '=', 'orders.user_id')
                    ->where('users.id', $userId)
                    ->get()
                    ->toArray();
            });
        } catch (CircuitBreakerOpenException $e) {
            // Fallback to primary database
            Log::warning('Read replica circuit open, using primary');

            return DB::connection('mysql')->table('users')
                ->join('orders', 'users.id', '=', 'orders.user_id')
                ->where('users.id', $userId)
                ->get()
                ->toArray();
        }
    }
}
```

## Microservices Communication

### Service Mesh Pattern

```php
namespace App\Services;

class MicroserviceClient
{
    public function callUserService(string $endpoint, array $data = []): array
    {
        return $this->callService('user-service', $endpoint, $data);
    }

    public function callOrderService(string $endpoint, array $data = []): array
    {
        return $this->callService('order-service', $endpoint, $data);
    }

    public function callInventoryService(string $endpoint, array $data = []): array
    {
        return $this->callService('inventory-service', $endpoint, $data);
    }

    private function callService(string $service, string $endpoint, array $data): array
    {
        $config = CircuitBreakerConfiguration::fromDefaults($service)
            ->withFailureThreshold(5)
            ->withTimeout(60);

        try {
            return Fuse::make($service, configuration: $config)->call(function () use ($service, $endpoint, $data) {
                $baseUrl = config("services.{$service}.url");

                return Http::timeout(10)
                    ->retry(2, 100)
                    ->post("{$baseUrl}{$endpoint}", $data)
                    ->throw()
                    ->json();
            });
        } catch (CircuitBreakerOpenException $e) {
            Log::error("Microservice {$service} unavailable", [
                'endpoint' => $endpoint,
                'circuit_state' => $e->name,
            ]);

            throw new ServiceUnavailableException("Service {$service} is temporarily unavailable");
        }
    }
}
```

### Saga Pattern with Circuit Breakers

```php
namespace App\Services;

class OrderSaga
{
    public function __construct(
        private MicroserviceClient $client
    ) {}

    public function createOrder(array $orderData): array
    {
        $createdResources = [];

        try {
            // Step 1: Reserve inventory
            $inventory = $this->client->callInventoryService('/reserve', [
                'product_id' => $orderData['product_id'],
                'quantity' => $orderData['quantity'],
            ]);
            $createdResources[] = ['service' => 'inventory', 'id' => $inventory['reservation_id']];

            // Step 2: Process payment
            $payment = $this->client->callPaymentService('/charge', [
                'amount' => $orderData['amount'],
                'token' => $orderData['payment_token'],
            ]);
            $createdResources[] = ['service' => 'payment', 'id' => $payment['charge_id']];

            // Step 3: Create order
            $order = $this->client->callOrderService('/orders', $orderData);
            $createdResources[] = ['service' => 'order', 'id' => $order['order_id']];

            return $order;

        } catch (ServiceUnavailableException $e) {
            // Compensate: rollback all created resources
            $this->compensate($createdResources);
            throw $e;
        }
    }

    private function compensate(array $resources): void
    {
        foreach (array_reverse($resources) as $resource) {
            try {
                $this->client->callService($resource['service'], '/rollback', [
                    'id' => $resource['id'],
                ]);
            } catch (\Exception $e) {
                Log::error("Compensation failed for {$resource['service']}", [
                    'resource' => $resource,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
```

## Background Jobs

### Job with Circuit Breaker

```php
namespace App\Jobs;

use Cline\Fuse\Facades\Fuse;
use Cline\Fuse\Exceptions\CircuitBreakerOpenException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class ProcessWebhook implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public $tries = 3;

    public function __construct(
        private array $webhookData
    ) {}

    public function handle(): void
    {
        try {
            Fuse::make('webhook-processor')->call(function () {
                // Process webhook
                Http::post(config('webhooks.processor_url'), $this->webhookData)
                    ->throw();
            });
        } catch (CircuitBreakerOpenException $e) {
            // Circuit open - retry in 5 minutes
            $this->release(300);
        }
    }
}
```

### Batch Processing

```php
namespace App\Jobs;

class ProcessBatchImport implements ShouldQueue
{
    public function handle(): void
    {
        $breaker = Fuse::make('import-processor');

        // Check circuit before starting batch
        if ($breaker->getState()->isOpen()) {
            Log::warning('Skipping batch import - circuit breaker open');
            $this->release(600); // Retry in 10 minutes
            return;
        }

        $records = DB::table('import_queue')->limit(1000)->get();

        foreach ($records as $record) {
            try {
                $breaker->call(function () use ($record) {
                    $this->processRecord($record);
                });
            } catch (CircuitBreakerOpenException $e) {
                // Circuit opened mid-batch - stop and retry later
                Log::warning('Circuit opened during batch processing', [
                    'processed' => $records->search($record),
                    'remaining' => $records->count() - $records->search($record),
                ]);

                $this->release(600);
                return;
            }
        }
    }
}
```

## Email and Notifications

### Email Service

```php
namespace App\Services;

class EmailService
{
    public function send(string $to, string $subject, string $body): bool
    {
        $config = CircuitBreakerConfiguration::fromDefaults('email-service')
            ->withFailureThreshold(10)  // Tolerant
            ->withTimeout(30);

        try {
            Fuse::make('email-service', configuration: $config)->call(function () use ($to, $subject, $body) {
                Mail::to($to)->send(new GenericEmail($subject, $body));
            });

            return true;
        } catch (CircuitBreakerOpenException $e) {
            // Queue email for later delivery
            SendEmailJob::dispatch($to, $subject, $body)
                ->delay(now()->addMinutes(5));

            Log::info('Email queued due to circuit breaker', [
                'to' => $to,
                'subject' => $subject,
            ]);

            return false;
        }
    }
}
```

### Push Notification Service

```php
namespace App\Services;

class PushNotificationService
{
    public function send(int $userId, string $title, string $body): void
    {
        try {
            Fuse::make('push-notifications')->call(function () use ($userId, $title, $body) {
                $user = User::findOrFail($userId);

                foreach ($user->devices as $device) {
                    $this->sendToDevice($device->token, $title, $body);
                }
            });
        } catch (CircuitBreakerOpenException $e) {
            // Push notifications not critical - just log
            Log::info('Push notification skipped - circuit open', [
                'user_id' => $userId,
                'title' => $title,
            ]);
        }
    }
}
```

## Cache Warming

### Cache Warmer with Circuit Breaker

```php
namespace App\Services;

class CacheWarmer
{
    public function warmProductCache(): void
    {
        try {
            Fuse::make('product-api')->call(function () {
                $products = Http::get('https://api.example.com/products')->json();

                Cache::put('products:all', $products, 3600);

                Log::info('Product cache warmed', [
                    'count' => count($products),
                ]);
            });
        } catch (CircuitBreakerOpenException $e) {
            // Keep using stale cache
            Log::warning('Cache warming failed - circuit open', [
                'cache_age' => Cache::get('products:all:timestamp'),
            ]);
        }
    }
}
```

## Search Services

### Elasticsearch Integration

```php
namespace App\Services;

class SearchService
{
    public function search(string $query): array
    {
        $config = CircuitBreakerConfiguration::fromDefaults('elasticsearch')
            ->withFailureThreshold(5)
            ->withTimeout(60);

        try {
            return Fuse::make('elasticsearch', configuration: $config)->call(function () use ($query) {
                return Elasticsearch::search([
                    'index' => 'products',
                    'body' => [
                        'query' => [
                            'match' => ['name' => $query],
                        ],
                    ],
                ]);
            });
        } catch (CircuitBreakerOpenException $e) {
            // Fallback to database search
            Log::info('Elasticsearch circuit open, using database search');

            return DB::table('products')
                ->where('name', 'LIKE', "%{$query}%")
                ->limit(50)
                ->get()
                ->toArray();
        }
    }
}
```

## Machine Learning APIs

### OpenAI Integration

```php
namespace App\Services;

class AIService
{
    public function generateCompletion(string $prompt): string
    {
        $config = CircuitBreakerConfiguration::fromDefaults('openai')
            ->withFailureThreshold(5)
            ->withTimeout(120) // AI can be slow
            ->withStrategy('rolling_window');

        try {
            return Fuse::make('openai', configuration: $config)->call(function () use ($prompt) {
                $response = Http::withToken(config('services.openai.key'))
                    ->timeout(30)
                    ->post('https://api.openai.com/v1/completions', [
                        'model' => 'gpt-3.5-turbo',
                        'prompt' => $prompt,
                        'max_tokens' => 150,
                    ])
                    ->throw()
                    ->json();

                return $response['choices'][0]['text'];
            });
        } catch (CircuitBreakerOpenException $e) {
            // Return pre-generated fallback
            return "Sorry, AI service is temporarily unavailable. Please try again later.";
        }
    }
}
```

## Multi-Tenant Applications

### Tenant-Specific Circuit Breakers

```php
namespace App\Services;

class TenantApiService
{
    public function callTenantApi(int $tenantId, string $endpoint): array
    {
        $circuitName = "tenant-api:{$tenantId}";

        $config = CircuitBreakerConfiguration::fromDefaults($circuitName)
            ->withFailureThreshold(5)
            ->withTimeout(60);

        try {
            return Fuse::make($circuitName, configuration: $config)->call(function () use ($tenantId, $endpoint) {
                $tenant = Tenant::findOrFail($tenantId);

                return Http::withToken($tenant->api_token)
                    ->get("{$tenant->api_url}{$endpoint}")
                    ->throw()
                    ->json();
            });
        } catch (CircuitBreakerOpenException $e) {
            Log::error("Tenant API unavailable", [
                'tenant_id' => $tenantId,
                'endpoint' => $endpoint,
            ]);

            throw new TenantApiUnavailableException();
        }
    }
}
```

## Real-Time Features

### Live Chat Integration

```php
namespace App\Services;

class LiveChatService
{
    public function sendMessage(int $conversationId, string $message): bool
    {
        try {
            Fuse::make('live-chat')->call(function () use ($conversationId, $message) {
                $this->chatProvider->send($conversationId, $message);
            });

            return true;
        } catch (CircuitBreakerOpenException $e) {
            // Store message for later delivery
            DB::table('chat_queue')->insert([
                'conversation_id' => $conversationId,
                'message' => $message,
                'created_at' => now(),
            ]);

            Log::info('Chat message queued', [
                'conversation_id' => $conversationId,
            ]);

            return false;
        }
    }
}
```

## Best Practices Summary

1. **Critical Services**
   - Low failure threshold (2-3)
   - Long timeout (120-180s)
   - Alert immediately
   - Throw exceptions (don't hide failures)

2. **Non-Critical Services**
   - Higher failure threshold (10-20)
   - Shorter timeout (30-60s)
   - Log warnings
   - Graceful degradation with fallbacks

3. **User-Facing Features**
   - Provide feedback when degraded
   - Cache aggressively
   - Queue for later processing
   - Show cached/stale data with indicators

4. **Background Processes**
   - Use circuit breakers to prevent wasted work
   - Implement proper retry logic
   - Monitor queue depths
   - Consider batch operations

5. **Multi-Tenant**
   - Isolate circuit breakers per tenant when possible
   - Monitor per-tenant health
   - Prevent noisy neighbor problems
   - Different thresholds for different tier

s

## Next Steps

- **[Getting Started](getting-started.md)** - Basic setup
- **[Configuration](configuration.md)** - Fine-tune for your use case
- **[Monitoring & Events](monitoring-and-events.md)** - Monitor production circuits
- **[Testing](testing.md)** - Test these patterns
