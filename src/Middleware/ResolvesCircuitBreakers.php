<?php

namespace Cline\Fuse\Middleware;

use Cline\Fuse\Attributes\UseCircuitBreaker;
use ReflectionClass;

/**
 * Maps job attributes to queue middleware instances.
 *
 * This keeps the attribute syntax declarative while still letting Laravel's
 * queue system consume concrete middleware objects at runtime.
 */
class ResolvesCircuitBreakers
{
    /**
     * Build circuit breaker middleware from the job's attributes.
     *
     * @return array<int, CircuitBreakerMiddleware>
     */
    public static function resolve(object $job): array
    {
        $reflection = new ReflectionClass($job);

        return array_map(
            static function ($attribute): CircuitBreakerMiddleware {
                $instance = $attribute->newInstance();

                return new CircuitBreakerMiddleware(
                    $instance->service,
                    release: $instance->release,
                    window: $instance->window,
                );
            },
            $reflection->getAttributes(UseCircuitBreaker::class)
        );
    }

    /**
     * Prepend attribute-derived middleware ahead of any existing job middleware.
     *
     * This ensures declared circuit breakers always wrap the downstream work
     * while still preserving any middleware the job already exposes.
     *
     * @param  array<int, object>  $middleware
     * @return array<int, object>
     */
    public static function merge(object $job, array $middleware = []): array
    {
        return [
            ...static::resolve($job),
            ...$middleware,
        ];
    }
}
