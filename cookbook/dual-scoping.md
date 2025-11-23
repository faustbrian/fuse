# Dual-Dimensional Scoping (Context + Boundary)

Circuit breakers can be scoped across two dimensions: **context** (WHO) and **boundary** (WHAT). This enables fine-grained isolation and rate limiting for complex multi-tenant, multi-integration applications.

## Why Use Dual Scoping?

**Context (WHO)**: The entity performing the action
- User, Tenant, Team, Organization
- Each gets their own isolated circuit breaker state
- One user's failures don't affect others

**Boundary (WHAT)**: The resource or integration being accessed
- External API, Stripe Account, Slack Workspace, Database Shard
- Independent failure tracking per integration
- Isolate third-party service issues

**Combined**: User-specific limits on specific integrations
- Per-user rate limiting on their Stripe account
- Per-tenant circuit breakers for their Slack workspace
- Per-organization database shard monitoring

## Scoping Combinations

### Global (No Context, No Boundary)

Shared across the entire application:

```php
// Everyone shares this circuit breaker
Fuse::make('external-api')->call(function () {
    return Http::get('https://api.example.com/data');
});
```

### Context Only (WHO)

Per-user, per-tenant, or per-team isolation:

```php
// Each user has their own circuit breaker for API calls
Fuse::for($user)->make('api-calls')->call(function () use ($user) {
    return $user->makeApiRequest();
});

// Each tenant has independent email service tracking
Fuse::for($tenant)->make('email-service')->call(function () use ($tenant) {
    return Mail::to($tenant->admin)->send(new WelcomeEmail());
});
```

### Boundary Only (WHAT)

Track failures for specific integrations globally:

```php
// Monitor all charges to this Stripe account
Fuse::boundary($stripeAccount)->make('charges')->call(function () use ($stripeAccount) {
    return $stripeAccount->charge(1000);
});

// Track health of specific external API
Fuse::boundary($externalApi)->make('fetch')->call(function () use ($externalApi) {
    return Http::get($externalApi->endpoint);
});
```

### Context + Boundary (WHO + WHAT)

User-specific limits on specific resources:

```php
// Each user has separate circuit breaker for THEIR Stripe account
Fuse::for($user)
    ->boundary($user->stripeAccount)
    ->make('charges')
    ->call(function () use ($user) {
        return $user->stripeAccount->charge(1000);
    });

// Each tenant has isolated circuit breaker for THEIR Slack workspace
Fuse::for($tenant)
    ->boundary($tenant->slackWorkspace)
    ->make('notifications')
    ->call(function () use ($tenant) {
        return $tenant->slackWorkspace->postMessage('Alert!');
    });
```

## Real-World Examples

### Multi-Tenant SaaS with Stripe

Each tenant can have multiple Stripe accounts. Track failures per tenant per account:

```php
class PaymentService
{
    public function charge(Tenant $tenant, StripeAccount $account, int $amount)
    {
        $config = CircuitBreakerConfiguration::fromDefaults('stripe-charges')
            ->withFailureThreshold(3)
            ->withTimeout(120);

        return Fuse::for($tenant)
            ->boundary($account)
            ->make('charges', configuration: $config)
            ->call(function () use ($account, $amount) {
                return $account->stripe()->charges->create([
                    'amount' => $amount,
                    'currency' => 'usd',
                ]);
            });
    }
}
```

**Benefits:**
- Tenant A's invalid Stripe account doesn't block Tenant B
- Same tenant's Account 1 failures don't affect their Account 2
- Per-tenant, per-account failure isolation

### User API Rate Limiting Per Integration

Users can integrate with multiple external services:

```php
class IntegrationController
{
    public function sync(Request $request, Integration $integration)
    {
        $user = $request->user();

        $config = CircuitBreakerConfiguration::fromDefaults('integration-sync')
            ->withFailureThreshold(10)
            ->withTimeout(300)
            ->withStrategy('rolling_window');

        try {
            return Fuse::for($user)
                ->boundary($integration)
                ->make('sync')
                ->call(function () use ($integration) {
                    return Http::withHeaders([
                        'Authorization' => "Bearer {$integration->api_token}",
                    ])->post($integration->sync_endpoint);
                });
        } catch (CircuitBreakerOpenException $e) {
            return response()->json([
                'error' => 'Rate limit exceeded for this integration.',
            ], 429);
        }
    }
}
```

**Benefits:**
- User A can't exhaust User B's quota
- User's Slack integration failures don't affect their GitHub integration
- Fine-grained per-user, per-service rate limiting

### Database Sharding with Per-Tenant Isolation

Route tenant queries to specific shards with monitoring:

```php
class ShardedQueryService
{
    public function query(Tenant $tenant, string $sql, array $bindings = [])
    {
        $shard = $this->getShardForTenant($tenant);

        return Fuse::for($tenant)
            ->boundary($shard)
            ->make('queries')
            ->call(function () use ($shard, $sql, $bindings) {
                return DB::connection($shard->connection_name)
                    ->select($sql, $bindings);
            });
    }

    private function getShardForTenant(Tenant $tenant): DatabaseShard
    {
        return DatabaseShard::find($tenant->database_shard_id);
    }
}
```

**Benefits:**
- Tenant queries on degraded shard don't affect other shards
- Per-tenant monitoring of their specific shard
- Automatic failover if shard becomes unhealthy

### Multi-Region External API Access

Users access region-specific API endpoints:

```php
class GeoDistributedApiService
{
    public function fetch(User $user, string $data)
    {
        $region = $this->getRegionForUser($user);

        return Fuse::for($user)
            ->boundary($region)
            ->make('api-fetch')
            ->call(function () use ($region, $data) {
                return Http::get("{$region->api_endpoint}/data/{$data}");
            });
    }
}
```

## Configuration

### Polymorphic Key Mapping

Configure separate key mappings for context and boundary models:

```php
// config/fuse.php

'morphKeyMap' => [
    // Context models (WHO)
    App\Models\User::class => 'uuid',
    App\Models\Tenant::class => 'ulid',
    App\Models\Team::class => 'id',
],

'boundaryMorphKeyMap' => [
    // Boundary models (WHAT)
    App\Models\StripeAccount::class => 'id',
    App\Models\SlackWorkspace::class => 'uuid',
    App\Models\DatabaseShard::class => 'id',
    App\Models\Integration::class => 'uuid',
],
```

### Enforced Key Mapping

Require explicit mappings to prevent accidents:

```php
'enforceMorphKeyMap' => [
    App\Models\User::class => 'uuid',
    App\Models\Tenant::class => 'ulid',
],

'enforceBoundaryMorphKeyMap' => [
    App\Models\StripeAccount::class => 'id',
    App\Models\SlackWorkspace::class => 'uuid',
],
```

With enforcement, this throws `MorphKeyViolationException`:

```php
// Team not in context mapping
Fuse::for($team)->make('api')->call($callable); // Exception!

// ExternalApi not in boundary mapping
Fuse::boundary($externalApi)->make('fetch')->call($callable); // Exception!
```

## Database Schema

Dual-scoped circuit breakers use both polymorphic relationships:

```sql
-- Global (no context, no boundary)
INSERT INTO circuit_breakers (name, context_type, context_id, boundary_type, boundary_id, state)
VALUES ('shared-api', NULL, NULL, NULL, NULL, 'closed');

-- Context only (per-user)
INSERT INTO circuit_breakers (name, context_type, context_id, boundary_type, boundary_id, state)
VALUES ('api-calls', 'App\\Models\\User', 'uuid-123', NULL, NULL, 'closed');

-- Boundary only (per-Stripe account)
INSERT INTO circuit_breakers (name, context_type, context_id, boundary_type, boundary_id, state)
VALUES ('charges', NULL, NULL, 'App\\Models\\StripeAccount', '456', 'closed');

-- Context + Boundary (per-user per-Stripe account)
INSERT INTO circuit_breakers (name, context_type, context_id, boundary_type, boundary_id, state)
VALUES ('charges', 'App\\Models\\User', 'uuid-123', 'App\\Models\\StripeAccount', '456', 'closed');
```

## Querying Circuit Breakers

### Find by Context and Boundary

```php
use Cline\Fuse\Database\CircuitBreaker;

// All circuit breakers for a user
$breakers = CircuitBreaker::forContext($user)->get();

// All circuit breakers for a Stripe account
$breakers = CircuitBreaker::forBoundary($stripeAccount)->get();

// Specific user + Stripe account combination
$breaker = CircuitBreaker::forContext($user)
    ->forBoundary($stripeAccount)
    ->where('name', 'charges')
    ->first();

// Global circuit breakers only
$globalBreakers = CircuitBreaker::global()->get();
```

### Direct Model Relationships

```php
// In your models
class User extends Model
{
    public function circuitBreakers()
    {
        return $this->morphMany(CircuitBreaker::class, 'context');
    }
}

class StripeAccount extends Model
{
    public function circuitBreakers()
    {
        return $this->morphMany(CircuitBreaker::class, 'boundary');
    }
}

// Usage
$user->circuitBreakers; // All circuit breakers with this user as context
$stripeAccount->circuitBreakers; // All circuit breakers with this account as boundary
```

## Cache Keys

Dual scoping is reflected in cache keys:

```php
// Global
circuit_breaker:shared-api:state

// Context only
circuit_breaker:App\Models\User:123:api-calls:state

// Boundary only
circuit_breaker:App\Models\StripeAccount:456:charges:state

// Context + Boundary
circuit_breaker:App\Models\User:123:App\Models\StripeAccount:456:charges:state
```

## Monitoring

Track circuit breaker state per context and boundary:

```php
Event::listen(CircuitBreakerOpened::class, function ($event) {
    $breaker = CircuitBreaker::where('name', $event->name)->first();

    $context = $breaker->context ? [
        'type' => $breaker->context_type,
        'id' => $breaker->context_id,
        'model' => $breaker->context,
    ] : null;

    $boundary = $breaker->boundary ? [
        'type' => $breaker->boundary_type,
        'id' => $breaker->boundary_id,
        'model' => $breaker->boundary,
    ] : null;

    Log::warning("Circuit breaker opened", [
        'name' => $event->name,
        'context' => $context,
        'boundary' => $boundary,
    ]);

    // Alert if specific integration is failing
    if ($boundary && $boundary['type'] === StripeAccount::class) {
        Notification::send($admin, new StripeAccountCircuitOpenNotification($breaker));
    }
});
```

## Testing

### Test Context + Boundary Isolation

```php
it('isolates circuit breakers by context and boundary', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $stripe1 = StripeAccount::factory()->create();
    $stripe2 = StripeAccount::factory()->create();

    // Open circuit for user1 + stripe1
    for ($i = 0; $i < 5; $i++) {
        try {
            Fuse::for($user1)->boundary($stripe1)->make('charges')->call(fn() => throw new Exception());
        } catch (Exception $e) {}
    }

    // User1 + Stripe1 circuit is open
    expect(fn() => Fuse::for($user1)->boundary($stripe1)->make('charges')->call(fn() => true))
        ->toThrow(CircuitBreakerOpenException::class);

    // User1 + Stripe2 circuit is still closed (different boundary)
    expect(Fuse::for($user1)->boundary($stripe2)->make('charges')->call(fn() => 'success'))
        ->toBe('success');

    // User2 + Stripe1 circuit is still closed (different context)
    expect(Fuse::for($user2)->boundary($stripe1)->make('charges')->call(fn() => 'success'))
        ->toBe('success');
});
```

### Test Different Scoping Levels

```php
it('maintains separate state for global, context, boundary, and dual scoped breakers', function () {
    $user = User::factory()->create();
    $stripe = StripeAccount::factory()->create();

    // Open global circuit breaker
    for ($i = 0; $i < 5; $i++) {
        try {
            Fuse::make('api')->call(fn() => throw new Exception());
        } catch (Exception $e) {}
    }

    // Global circuit is open
    expect(fn() => Fuse::make('api')->call(fn() => true))
        ->toThrow(CircuitBreakerOpenException::class);

    // Context-scoped circuit is still closed
    expect(Fuse::for($user)->make('api')->call(fn() => 'success'))
        ->toBe('success');

    // Boundary-scoped circuit is still closed
    expect(Fuse::boundary($stripe)->make('api')->call(fn() => 'success'))
        ->toBe('success');

    // Dual-scoped circuit is still closed
    expect(Fuse::for($user)->boundary($stripe)->make('api')->call(fn() => 'success'))
        ->toBe('success');
});
```

## Best Practices

**Choose the Right Scoping Level**
- Global: Truly shared services with no isolation needed
- Context: Per-user/tenant rate limiting and isolation
- Boundary: Per-integration health monitoring
- Context + Boundary: Fine-grained per-user per-integration tracking

**Name Consistently**
- Use descriptive names: `charges`, `notifications`, `api-sync`
- Same name with different scopes creates separate breakers
- Document your naming convention

**Configure Appropriately**
- Dual-scoped breakers often need different thresholds
- User boundaries might need stricter limits than tenant boundaries
- Integration boundaries might need longer timeouts

**Monitor Scope Distribution**
- Track which context+boundary combinations have open circuits
- Identify problematic users, tenants, or integrations
- Alert on widespread failures across multiple scopes

**Clean Up Old Scopes**
- Prune circuit breakers for deleted contexts/boundaries
- Implement soft-delete observers if needed
- Monitor database growth

**Test Isolation Thoroughly**
- Verify context isolation works correctly
- Verify boundary isolation works correctly
- Verify dual scoping doesn't leak between combinations
- Test all four scoping levels (global, context, boundary, dual)

## Next Steps

- [Advanced Usage](advanced-usage.md) - Custom strategies and multi-store patterns
- [Monitoring and Events](monitoring-and-events.md) - Track scope-specific metrics
- [Testing](testing.md) - Test strategies for dual-scoped isolation
