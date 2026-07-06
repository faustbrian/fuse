<?php

namespace Cline\Fuse\Commands;

use Cline\Fuse\CircuitBreaker;
use Illuminate\Console\Command;

/**
 * Reset one or all breakers back to their initial state.
 *
 * Resetting removes persisted state, counters, and probe locks. This is
 * stronger than simply closing a breaker because it also clears the recent
 * failure history that would otherwise continue to influence the next window.
 */
class FuseResetCommand extends Command
{
    protected $signature = 'fuse:reset {service?}';

    protected $description = 'Reset circuit breakers to closed state';

    /**
     * Reset the selected breaker, or every configured breaker when omitted.
     */
    public function handle(): int
    {
        $service = $this->argument('service');

        if ($service && ! array_key_exists($service, config('fuse.services', []))) {
            $this->warn("Service '{$service}' is not configured in config/fuse.php");

            return self::SUCCESS;
        }

        $services = $service
            ? [$service]
            : array_keys(config('fuse.services', []));

        if (empty($services)) {
            $this->warn('No services configured in config/fuse.php');

            return self::SUCCESS;
        }

        foreach ($services as $service) {
            (new CircuitBreaker((string) $service))->reset();

            $this->info("Circuit breaker {$service} has been reset to closed state");
        }

        return self::SUCCESS;
    }
}
