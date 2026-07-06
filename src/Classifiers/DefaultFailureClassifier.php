<?php

namespace Cline\Fuse\Classifiers;

use GuzzleHttp\Exception\ClientException;
use Cline\Fuse\Contracts\FailureClassifier;
use Illuminate\Http\Client\RequestException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Default exception classifier used when a service does not provide its own.
 *
 * The classifier treats transport and server-side failures as breaker-worthy
 * by default, but ignores throttling and authorization-style responses that
 * usually reflect request policy rather than dependency health.
 */
class DefaultFailureClassifier implements FailureClassifier
{
    /** @var int[] */
    public const EXCLUDED_STATUS_CODES = [429, 401, 403];

    /**
     * Determine whether the given exception should increase the breaker's failure rate.
     *
     * Guzzle and Laravel HTTP client exceptions are inspected for response
     * status codes so expected policy responses can be excluded without
     * suppressing genuine service failures.
     */
    public function shouldCount(Throwable $e): bool
    {
        if ($e instanceof TooManyRequestsHttpException) {
            return false;
        }

        if ($e instanceof ClientException) {
            return match (true) {
                in_array($e->getResponse()->getStatusCode(), self::EXCLUDED_STATUS_CODES, true) => false,
                default => true,
            };
        }

        if ($e instanceof RequestException) {
            return match (true) {
                in_array($e->response->status(), self::EXCLUDED_STATUS_CODES, true) => false,
                default => true,
            };
        }

        return true;
    }
}
