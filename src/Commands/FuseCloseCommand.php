<?php

namespace Cline\Fuse\Commands;

use Cline\Fuse\CircuitBreaker;
use Illuminate\Console\Command;

/**
 * Manually close a configured circuit breaker.
 *
 * This command is intended for operators who have confirmed a dependency is
 * healthy again and want to resume normal flow without waiting for the next
 * half-open recovery cycle to close the breaker organically.
 */
class FuseCloseCommand extends Command
{
    protected $signature = 'fuse:close {service}
        {--preserve-history : Keep the current window counters when closing}';

    protected $description = 'Manually close circuit breaker';

    /**
     * Close the selected service breaker if the service exists in config.
     */
    public function handle(): int
    {
        $service = $this->argument('service');

        if (! array_key_exists($service, config('fuse.services', []))) {
            $this->warn("Service '{$service}' is not configured in config/fuse.php");

            return self::SUCCESS;
        }

        $preserveHistory = (bool) $this->option('preserve-history');

        (new CircuitBreaker($service))->forceClose($preserveHistory);

        $this->info("Circuit breaker for {$service} has been manually closed.");

        return self::SUCCESS;
    }
}
