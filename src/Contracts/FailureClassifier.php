<?php

namespace Cline\Fuse\Contracts;

use Throwable;

/**
 * Decides whether an observed exception should count toward tripping a breaker.
 *
 * Implementations let each service define what constitutes a health failure.
 * This is useful when some HTTP or domain exceptions are expected outcomes and
 * should not contribute to outage detection.
 */
interface FailureClassifier
{
    /**
     * Determine whether the exception should increase the failure count.
     */
    public function shouldCount(Throwable $e): bool;
}
