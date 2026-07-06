## Table of Contents

1. [Getting Started](#doc-docs-readme)
2. [Job Middleware](#doc-docs-job-middleware)
3. [Attributes](#doc-docs-attributes)
4. [Failure Classification](#doc-docs-failure-classification)
5. [Recovery Strategies](#doc-docs-recovery-strategies)
6. [Configuration](#doc-docs-configuration)
7. [Commands](#doc-docs-commands)

<a id="doc-docs-readme"></a>

Fuse is a Laravel circuit breaker package built for queue jobs that talk
to external services. It tracks failures over time, opens the circuit
when a dependency becomes unreliable, and lets traffic recover
automatically after the timeout window.

## Installation

Install Fuse with composer:

```bash
composer require cline/fuse
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=fuse-config
```

## Core Flow

Every protected service moves through three states:

- `closed`: requests flow normally and failures are tracked
- `open`: jobs release immediately instead of repeatedly calling a
  failing dependency
- `half_open`: a limited recovery attempt is allowed after timeout

Fuse stores its counters in cache and evaluates state transitions per
service.

<a id="doc-docs-job-middleware"></a>

## Job Middleware

Use the middleware directly on queue jobs:

```php
use Cline\Fuse\Middleware\CircuitBreakerMiddleware;

class ChargeCustomer implements ShouldQueue
{
    public function middleware(): array
    {
        return [
            new CircuitBreakerMiddleware('stripe', release: 20),
        ];
    }
}
```

If the circuit is open, the job is released instead of calling the
dependency again. Successful executions record success; thrown
exceptions are classified and may record failure.

<a id="doc-docs-attributes"></a>

## Attributes

Fuse can also resolve circuit breakers from job attributes:

```php
use Cline\Fuse\Attributes\UseCircuitBreaker;
use Cline\Fuse\Middleware\ResolvesCircuitBreakers;

#[UseCircuitBreaker('stripe', release: 20)]
class ChargeCustomer implements ShouldQueue
{
    public function middleware(): array
    {
        return ResolvesCircuitBreakers::resolve($this);
    }
}
```

For jobs that already have middleware, prepend the generated middleware:

```php
return ResolvesCircuitBreakers::merge($this, [
    new RateLimited('payments'),
]);
```

<a id="doc-docs-failure-classification"></a>

## Failure Classification

Not every exception should trip a circuit. Fuse excludes common
non-outage cases such as rate limits and authorization errors by
default.

Override this per service with a custom classifier:

```php
'services' => [
    'stripe' => [
        'failure_classifier' => \App\Fuse\StripeFailureClassifier::class,
    ],
],
```

Custom classifiers implement `Cline\Fuse\Contracts\FailureClassifier`.

<a id="doc-docs-recovery-strategies"></a>

## Recovery Strategies

Fuse defaults to a single-probe recovery model: once a circuit reaches
`half_open`, one attempt is allowed through. Success closes the circuit;
failure reopens it.

If a service needs a different recovery shape, provide a custom
strategy:

```php
'services' => [
    'stripe' => [
        'recovery_strategy' => \App\Fuse\CustomRecoveryStrategy::class,
    ],
],
```

Custom strategies implement
`Cline\Fuse\Contracts\RecoveryStrategy`.

<a id="doc-docs-configuration"></a>

## Configuration

The configuration file defines global defaults plus per-service
overrides:

```php
return [
    'enabled' => env('FUSE_ENABLED', true),
    'default_threshold' => 50,
    'default_timeout' => 60,
    'default_min_requests' => 10,
    'default_release' => 10,
    'default_window' => 60,

    'services' => [
        'stripe' => [
            'threshold' => 50,
            'timeout' => 30,
            'min_requests' => 5,
            'release' => 15,
            'window' => 300,
            'peak_hours_threshold' => 60,
            'peak_hours_start' => 9,
            'peak_hours_end' => 17,
        ],
    ],

    'cache' => [
        'prefix' => env('FUSE_CACHE_PREFIX', 'fuse'),
    ],
];
```

Key tuning points:

- `threshold`: failure-rate percentage that opens the circuit
- `timeout`: seconds before recovery is attempted
- `min_requests`: minimum sample size before evaluating failure rate
- `release`: delay used when an open circuit releases jobs
- `window`: tumbling bucket duration for failure tracking

<a id="doc-docs-commands"></a>

## Commands

Fuse exposes Artisan commands for inspecting and managing circuits:

```bash
php artisan fuse:status
php artisan fuse:status stripe
php artisan fuse:open stripe
php artisan fuse:close stripe
php artisan fuse:reset
php artisan fuse:reset stripe
```

These commands are useful when you need to inspect current state or
manually force a circuit transition during incident response.
