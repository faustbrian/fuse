<?php

namespace Cline\Fuse\Attributes;

use Attribute;

/**
 * Declare queue middleware-based circuit breaker protection on a job class.
 *
 * Jobs can repeat this attribute to gate multiple downstream dependencies.
 * The attribute is resolved into `CircuitBreakerMiddleware` instances at
 * runtime so the job class can describe its external-service boundaries close
 * to the code that performs the work.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class UseCircuitBreaker
{
    /**
     * @param  string  $service  Configured service key under `fuse.services`
     * @param  int|null  $release  Optional queue release delay when the breaker is open
     * @param  int|null  $window  Optional override for the breaker's tracking window
     */
    public function __construct(
        public readonly string $service,
        public readonly ?int $release = null,
        public readonly ?int $window = null,
    ) {}
}
