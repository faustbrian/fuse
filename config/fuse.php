<?php

/*
|--------------------------------------------------------------------------
| Fuse Package Configuration
|--------------------------------------------------------------------------
|
| Fuse applies cache-backed circuit breakers to queue jobs that depend on
| external services. These options define the default trip rules, the
| per-service overrides, and the cache namespace used to share breaker state
| across workers.
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Enabled
    |--------------------------------------------------------------------------
    |
    | Global toggle for circuit breaker functionality. When disabled, all
    | jobs will pass through without circuit breaker protection.
    |
    */
    'enabled' => env('FUSE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default Failure Threshold
    |--------------------------------------------------------------------------
    |
    | This value defines the default failure percentage required to open a
    | circuit breaker. Individual services may override this threshold when
    | they need a stricter or more tolerant failure budget.
    |
    */
    'default_threshold' => 50,

    /*
    |--------------------------------------------------------------------------
    | Default Open Timeout
    |--------------------------------------------------------------------------
    |
    | This value determines how long a breaker remains open before Fuse will
    | allow recovery traffic to begin in the half-open state. Individual
    | services may override this timeout when needed.
    |
    */
    'default_timeout' => 60,

    /*
    |--------------------------------------------------------------------------
    | Default Minimum Requests
    |--------------------------------------------------------------------------
    |
    | This value sets the minimum number of tracked attempts required before
    | Fuse evaluates the failure rate for a service. It helps prevent low
    | traffic services from opening their breakers too aggressively.
    |
    */
    'default_min_requests' => 10,

    /*
    |--------------------------------------------------------------------------
    | Default Release Delay
    |--------------------------------------------------------------------------
    |
    | This value controls how many seconds a queued job should be delayed when
    | its service breaker is open or when half-open recovery denies the job's
    | attempt. Services may override this delay individually.
    |
    */
    'default_release' => 10,

    /*
    |--------------------------------------------------------------------------
    | Default Tracking Window
    |--------------------------------------------------------------------------
    |
    | This value defines the default tumbling window used to count attempts and
    | failures for each service. Increase the window for low-throughput queues
    | that need a longer period before their failure rate becomes meaningful.
    |
    */
    'default_window' => 60,

    /*
    |--------------------------------------------------------------------------
    | Default Probe Lease
    |--------------------------------------------------------------------------
    |
    | This value determines how long the default SingleProbe recovery strategy
    | reserves half-open probe ownership for one worker. Services may override
    | this when recovery attempts are expected to run longer or shorter.
    |
    */
    'default_probe_lease' => 30,

    /*
    |--------------------------------------------------------------------------
    | Service Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure circuit breaker behavior for each downstream
    | dependency your queue jobs interact with. Every service may override the
    | package defaults and may also define custom classifier or recovery
    | strategy classes.
    |
    | The example service below shows every supported option. Uncomment and
    | adapt entries to match the external systems your jobs depend on.
    |
    */
    'services' => [

        /*
        |--------------------------------------------------------------------------
        | Example Service
        |--------------------------------------------------------------------------
        |
        | This example demonstrates the complete set of per-service options
        | supported by Fuse. Uncomment the service and adjust each value to fit
        | the dependency's traffic profile and recovery characteristics.
        |
        */

        // 'stripe' => [
        //
        //     /*
        //     |--------------------------------------------------------------------------
        //     | Failure Threshold
        //     |--------------------------------------------------------------------------
        //     |
        //     | The failure percentage required to open the breaker for this
        //     | service. Once the minimum request count is reached, Fuse will
        //     | compare the service's failure rate against this threshold.
        //     |
        //     */
        //     'threshold' => 50,
        //
        //     /*
        //     |--------------------------------------------------------------------------
        //     | Open Timeout
        //     |--------------------------------------------------------------------------
        //     |
        //     | The number of seconds this breaker should remain open before
        //     | Fuse transitions it to half-open and allows recovery probes.
        //     |
        //     */
        //     'timeout' => 30,
        //
        //     /*
        //     |--------------------------------------------------------------------------
        //     | Minimum Requests
        //     |--------------------------------------------------------------------------
        //     |
        //     | The minimum number of tracked attempts required before Fuse
        //     | evaluates the breaker for this service.
        //     |
        //     */
        //     'min_requests' => 5,
        //
        //     /*
        //     |--------------------------------------------------------------------------
        //     | Release Delay
        //     |--------------------------------------------------------------------------
        //     |
        //     | The delay, in seconds, applied when jobs are released because
        //     | the breaker is open or the recovery strategy denied the probe.
        //     |
        //     */
        //     'release' => 10,
        //
        //     /*
        //     |--------------------------------------------------------------------------
        //     | Tracking Window
        //     |--------------------------------------------------------------------------
        //     |
        //     | The number of seconds in each accounting window for attempts
        //     | and failures for this service.
        //     |
        //     */
        //     'window' => 300,
        //
        //     /*
        //     |--------------------------------------------------------------------------
        //     | Probe Lease
        //     |--------------------------------------------------------------------------
        //     |
        //     | The number of seconds the default SingleProbe strategy should
        //     | reserve ownership of a half-open recovery attempt.
        //     |
        //     */
        //     'probe_lease' => 30,
        //
        //     /*
        //     |--------------------------------------------------------------------------
        //     | Peak Hours Threshold
        //     |--------------------------------------------------------------------------
        //     |
        //     | An alternate failure threshold used during the configured peak
        //     | hours for this service.
        //     |
        //     */
        //     'peak_hours_threshold' => 60,
        //
        //     /*
        //     |--------------------------------------------------------------------------
        //     | Peak Hours Start
        //     |--------------------------------------------------------------------------
        //     |
        //     | The hour of day, using 24-hour time, when the service's peak
        //     | traffic window begins.
        //     |
        //     */
        //     'peak_hours_start' => 9,
        //
        //     /*
        //     |--------------------------------------------------------------------------
        //     | Peak Hours End
        //     |--------------------------------------------------------------------------
        //     |
        //     | The hour of day, using 24-hour time, when the service's peak
        //     | traffic window ends. Overnight windows such as 22 to 6 are
        //     | also supported.
        //     |
        //     */
        //     'peak_hours_end' => 17,
        //
        //     /*
        //     |--------------------------------------------------------------------------
        //     | Failure Classifier
        //     |--------------------------------------------------------------------------
        //     |
        //     | The classifier class used to decide whether an exception
        //     | should count toward the breaker's failure rate.
        //     |
        //     */
        //     'failure_classifier' => \App\Fuse\StripeFailureClassifier::class,
        //
        //     /*
        //     |--------------------------------------------------------------------------
        //     | Recovery Strategy
        //     |--------------------------------------------------------------------------
        //     |
        //     | The recovery strategy class that coordinates traffic while the
        //     | breaker is half-open.
        //     |
        //     */
        //     'recovery_strategy' => \App\Fuse\StripeRecoveryStrategy::class,
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the cache key prefix used for storing circuit breaker state.
    | This is useful when running multiple applications on the same cache
    | store to avoid key collisions.
    |
    */
    'cache' => [

        /*
        |--------------------------------------------------------------------------
        | Cache Prefix
        |--------------------------------------------------------------------------
        |
        | This prefix namespaces every breaker state, counter, and lock key
        | written by Fuse so multiple applications can safely share the same
        | cache backend.
        |
        */
        'prefix' => env('FUSE_CACHE_PREFIX', 'fuse'),
    ],
];
