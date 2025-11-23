<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
|--------------------------------------------------------------------------
| Fuse Circuit Breaker Configuration
|--------------------------------------------------------------------------
|
| This file defines the configuration for Fuse, a Laravel circuit breaker
| package providing fault tolerance and resilience for distributed systems.
| Circuit breakers prevent cascading failures by automatically detecting
| faults and temporarily blocking requests to failing services, allowing
| them time to recover while maintaining system stability.
|
*/

use Cline\Fuse\Database\CircuitBreaker;
use Cline\Fuse\Database\CircuitBreakerEvent;
use Cline\Fuse\Strategies\ConsecutiveFailuresStrategy;
use Cline\Fuse\Strategies\PercentageFailuresStrategy;
use Cline\Fuse\Strategies\RollingWindowStrategy;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Circuit Breaker Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default circuit breaker store that will be used
    | by the framework. This connection is utilised if another isn't
    | explicitly specified when checking a circuit breaker in the application.
    |
    */

    'default' => env('FUSE_STORE', 'cache'),

    /*
    |--------------------------------------------------------------------------
    | Primary Key Type
    |--------------------------------------------------------------------------
    |
    | This option controls the type of primary key used in Fuse's database
    | tables. You may use traditional auto-incrementing integers or choose
    | ULIDs or UUIDs for distributed systems or enhanced privacy.
    |
    | Supported: "id", "ulid", "uuid"
    |
    */

    'primary_key_type' => env('FUSE_PRIMARY_KEY_TYPE', 'id'),

    /*
    |--------------------------------------------------------------------------
    | Eloquent Models
    |--------------------------------------------------------------------------
    |
    | When using the database driver, Fuse needs to know which Eloquent models
    | should be used to interact with the database. You may extend these models
    | with your own implementations whilst ensuring they extend the base classes
    | provided by Fuse.
    |
    */

    'models' => [
        /*
        |----------------------------------------------------------------------
        | Circuit Breaker Model
        |----------------------------------------------------------------------
        |
        | This model is used to retrieve your circuit breakers from the database.
        | The model you specify must extend the `Cline\Fuse\Database\CircuitBreaker`
        | class. This allows you to customise the circuit breaker model behaviour
        | whilst maintaining compatibility with Fuse's internal operations.
        |
        */

        'circuit_breaker' => CircuitBreaker::class,

        /*
        |----------------------------------------------------------------------
        | Circuit Breaker Event Model
        |----------------------------------------------------------------------
        |
        | This model is used to retrieve circuit breaker events from the database.
        | The model you specify must extend the `Cline\Fuse\Database\CircuitBreakerEvent`
        | class. Events track state transitions and provide an audit trail of
        | circuit breaker operations for monitoring and debugging.
        |
        */

        'circuit_breaker_event' => CircuitBreakerEvent::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Table Names
    |--------------------------------------------------------------------------
    |
    | When using the database driver, Fuse needs to know which table names
    | should be used to store your circuit breakers and events. These table
    | names are used by both the migrations and Eloquent models.
    |
    */

    'table_names' => [
        /*
        |----------------------------------------------------------------------
        | Circuit Breakers Table
        |----------------------------------------------------------------------
        |
        | This table stores circuit breaker definitions, state, failure counts,
        | timestamps, and configuration. It serves as the central repository
        | for all circuit breakers when using the database driver.
        |
        */

        'circuit_breakers' => env('FUSE_CIRCUIT_BREAKERS_TABLE', 'circuit_breakers'),

        /*
        |----------------------------------------------------------------------
        | Circuit Breaker Events Table
        |----------------------------------------------------------------------
        |
        | This table stores an audit trail of all circuit breaker state
        | transitions (opened, closed, half-open) including when they occurred
        | and the context. This provides full accountability for debugging
        | and monitoring circuit breaker behavior.
        |
        */

        'circuit_breaker_events' => env('FUSE_CIRCUIT_BREAKER_EVENTS_TABLE', 'circuit_breaker_events'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the circuit breaker stores for your application
    | as well as their drivers. You may even define multiple stores for the
    | same driver to group types of circuit breakers in your application.
    |
    | Supported drivers: "array", "database", "cache"
    |
    */

    'stores' => [
        /*
        |----------------------------------------------------------------------
        | Array Store
        |----------------------------------------------------------------------
        |
        | The array store keeps circuit breakers in memory for the duration of
        | the request. This is ideal for testing environments or when you need
        | temporary circuit breakers that don't persist between requests. Perfect
        | for unit tests and development environments.
        |
        */

        'array' => [
            'driver' => 'array',
        ],

        /*
        |----------------------------------------------------------------------
        | Database Store
        |----------------------------------------------------------------------
        |
        | The database store persists circuit breakers to your database, allowing
        | them to maintain state across requests and servers. This is recommended
        | for production environments where circuit breaker state needs to be
        | shared across multiple application instances.
        |
        | You may specify a custom database connection to isolate circuit breaker
        | data from your primary application database.
        |
        */

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CONNECTION'),
        ],

        /*
        |----------------------------------------------------------------------
        | Cache Store
        |----------------------------------------------------------------------
        |
        | The cache store leverages your application's cache system to store
        | circuit breaker state with excellent performance. This is the recommended
        | approach for most production environments, especially when using Redis
        | or Memcached for distributed caching across multiple servers.
        |
        */

        'cache' => [
            /*
            |------------------------------------------------------------------
            | Cache Driver
            |------------------------------------------------------------------
            |
            | This value determines which cache driver will be used to store your
            | circuit breakers. This should match one of the cache stores defined
            | in your cache configuration file.
            |
            */

            'driver' => 'cache',

            /*
            |------------------------------------------------------------------
            | Cache Store
            |------------------------------------------------------------------
            |
            | This value determines which cache store will be used. Leave null
            | to use the default cache store from your cache configuration.
            |
            */

            'store' => env('FUSE_CACHE_STORE'),

            /*
            |------------------------------------------------------------------
            | Cache Key Prefix
            |------------------------------------------------------------------
            |
            | When utilising a cache store, you may wish to prefix your circuit
            | breaker keys to avoid collisions with other cached data. This prefix
            | is prepended to all circuit breaker keys stored in the cache.
            |
            */

            'prefix' => env('FUSE_CACHE_PREFIX', 'circuit_breaker'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Circuit Breaker Settings
    |--------------------------------------------------------------------------
    |
    | These settings define the default behavior for all circuit breakers in
    | your application. Individual circuit breakers may override these defaults
    | when configured. These values control when circuit breakers open, how long
    | they stay open, and when they attempt recovery.
    |
    */

    'defaults' => [
        /*
        |----------------------------------------------------------------------
        | Failure Threshold
        |----------------------------------------------------------------------
        |
        | The number of consecutive failures required before the circuit breaker
        | trips to the open state. A lower threshold makes the circuit breaker
        | more sensitive to failures, while a higher threshold tolerates more
        | intermittent failures before opening.
        |
        */

        'failure_threshold' => env('FUSE_FAILURE_THRESHOLD', 5),

        /*
        |----------------------------------------------------------------------
        | Success Threshold
        |----------------------------------------------------------------------
        |
        | The number of consecutive successful requests required in half-open
        | state before the circuit breaker closes completely. This ensures the
        | service has stabilized before fully restoring traffic.
        |
        */

        'success_threshold' => env('FUSE_SUCCESS_THRESHOLD', 2),

        /*
        |----------------------------------------------------------------------
        | Timeout
        |----------------------------------------------------------------------
        |
        | The duration in seconds that the circuit breaker remains in the open
        | state before transitioning to half-open. During this timeout, all
        | requests are immediately rejected, giving the failing service time
        | to recover. After the timeout expires, the circuit breaker enters
        | half-open state to test if the service has recovered.
        |
        */

        'timeout' => env('FUSE_TIMEOUT', 60),

        /*
        |----------------------------------------------------------------------
        | Sampling Duration
        |----------------------------------------------------------------------
        |
        | For percentage-based and rolling window strategies, this defines the
        | time window in seconds over which failures are calculated. A shorter
        | duration makes the circuit breaker more reactive to recent failures,
        | while a longer duration provides a more stable view of service health.
        |
        */

        'sampling_duration' => env('FUSE_SAMPLING_DURATION', 120),

        /*
        |----------------------------------------------------------------------
        | Minimum Throughput
        |----------------------------------------------------------------------
        |
        | The minimum number of requests required within the sampling duration
        | before percentage-based calculations are considered valid. This prevents
        | the circuit breaker from opening due to low sample sizes (e.g., 1 failure
        | out of 1 request = 100% failure rate).
        |
        */

        'minimum_throughput' => env('FUSE_MINIMUM_THROUGHPUT', 10),

        /*
        |----------------------------------------------------------------------
        | Percentage Threshold
        |----------------------------------------------------------------------
        |
        | For percentage-based strategies, this defines the failure rate percentage
        | that will cause the circuit breaker to open. For example, a value of 50
        | means the circuit breaker will open if 50% or more of requests fail
        | within the sampling duration (provided minimum throughput is met).
        |
        */

        'percentage_threshold' => env('FUSE_PERCENTAGE_THRESHOLD', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Evaluation Strategies
    |--------------------------------------------------------------------------
    |
    | Circuit breakers can use different strategies to determine when to open.
    | Each strategy implements different evaluation logic for determining
    | circuit breaker state transitions based on request outcomes.
    |
    */

    'strategies' => [
        /*
        |----------------------------------------------------------------------
        | Default Strategy
        |----------------------------------------------------------------------
        |
        | This option determines which strategy will be used as the default when
        | creating new circuit breakers without explicitly specifying a strategy.
        | The consecutive failures strategy is recommended for most use cases.
        |
        */

        'default' => env('FUSE_DEFAULT_STRATEGY', 'consecutive_failures'),

        /*
        |----------------------------------------------------------------------
        | Available Strategies
        |----------------------------------------------------------------------
        |
        | Here you may define all available strategies that can be assigned to
        | circuit breakers. Each strategy implements different evaluation logic:
        |
        | - consecutive_failures: Opens after N consecutive failures
        | - percentage_failures: Opens when failure rate exceeds threshold
        | - rolling_window: Evaluates failures over a sliding time window
        |
        | You may add custom strategies by implementing the Strategy contract
        | and registering them here.
        |
        */

        'available' => [
            'consecutive_failures' => ConsecutiveFailuresStrategy::class,
            'percentage_failures' => PercentageFailuresStrategy::class,
            'rolling_window' => RollingWindowStrategy::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Octane Reset Listener
    |--------------------------------------------------------------------------
    |
    | When set to true, Fuse will automatically register an event listener for
    | Laravel\Octane\Contracts\OperationTerminated to flush the circuit breaker
    | cache after each Octane operation (request, task, tick). This ensures
    | fresh state evaluations in long-running processes. Disable this in
    | testing environments or when you need manual cache control.
    |
    */

    'register_octane_reset_listener' => env('FUSE_REGISTER_OCTANE_RESET_LISTENER', true),

    /*
    |--------------------------------------------------------------------------
    | Events Configuration
    |--------------------------------------------------------------------------
    |
    | Configure event dispatching behavior for circuit breaker operations.
    |
    | enabled: When true, Fuse will dispatch CircuitBreakerOpened,
    |          CircuitBreakerClosed, and CircuitBreakerHalfOpened events during
    |          state transitions. This enables event-driven workflows such as
    |          logging, notifications, or automated responses to circuit breaker
    |          state changes. Disable this if you don't need event-based
    |          functionality to reduce overhead.
    |
    | Events that will fire during circuit breaker operations:
    | - \Cline\Fuse\Events\CircuitBreakerOpened
    | - \Cline\Fuse\Events\CircuitBreakerClosed
    | - \Cline\Fuse\Events\CircuitBreakerHalfOpened
    | - \Cline\Fuse\Events\CircuitBreakerRequestAttempted
    | - \Cline\Fuse\Events\CircuitBreakerRequestSucceeded
    | - \Cline\Fuse\Events\CircuitBreakerRequestFailed
    |
    */

    'events' => [
        'enabled' => env('FUSE_EVENTS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Polymorphic Context Key Mapping
    |--------------------------------------------------------------------------
    |
    | Define which primary key column each model uses for context relationships.
    | This allows circuit breakers to be scoped to specific models (users,
    | tenants, teams) with different key types (id, uuid, ulid).
    |
    | Example:
    | 'morphKeyMap' => [
    |     App\Models\User::class => 'uuid',
    |     App\Models\Tenant::class => 'ulid',
    | ],
    |
    */

    'morphKeyMap' => [],

    /*
    |--------------------------------------------------------------------------
    | Enforced Context Key Mapping
    |--------------------------------------------------------------------------
    |
    | When set, all models used as context must have an explicit key mapping.
    | Attempting to use an unmapped model will throw MorphKeyViolationException.
    | This ensures you don't accidentally use models with incorrect key types.
    |
    | Example:
    | 'enforceMorphKeyMap' => [
    |     App\Models\User::class => 'uuid',
    |     App\Models\Tenant::class => 'ulid',
    | ],
    |
    */

    'enforceMorphKeyMap' => [],

    /*
    |--------------------------------------------------------------------------
    | Polymorphic Boundary Key Mapping
    |--------------------------------------------------------------------------
    |
    | Define which primary key column each model uses for boundary relationships.
    | This allows circuit breakers to track failures for specific integrations
    | or external services (Stripe accounts, Slack workspaces, APIs) with
    | different key types.
    |
    | Example:
    | 'boundaryMorphKeyMap' => [
    |     App\Models\StripeAccount::class => 'id',
    |     App\Models\SlackWorkspace::class => 'uuid',
    | ],
    |
    */

    'boundaryMorphKeyMap' => [],

    /*
    |--------------------------------------------------------------------------
    | Enforced Boundary Key Mapping
    |--------------------------------------------------------------------------
    |
    | When set, all models used as boundaries must have an explicit key mapping.
    | Attempting to use an unmapped model will throw MorphKeyViolationException.
    |
    | Example:
    | 'enforceBoundaryMorphKeyMap' => [
    |     App\Models\StripeAccount::class => 'id',
    |     App\Models\SlackWorkspace::class => 'uuid',
    | ],
    |
    */

    'enforceBoundaryMorphKeyMap' => [],

    /*
    |--------------------------------------------------------------------------
