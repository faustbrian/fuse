<?php

namespace Cline\Fuse\Commands;

use Cline\Fuse\CircuitBreaker;
use Illuminate\Console\Command;

/**
 * Manually trip a configured circuit breaker open.
 *
 * This gives operators a way to stop queue traffic against a dependency that
 * is known to be degraded even before Fuse has observed enough failures to
 * open the circuit automatically.
 */
class FuseOpenCommand extends Command
{
    protected $signature = 'fuse:open {service}';

    protected $description = 'Manually open circuit breaker';

    /**
     * Open the selected service breaker if the service exists in config.
     */
    public function handle(): int
    {
        $service = $this->argument('service');

        if (! array_key_exists($service, config('fuse.services', []))) {
            $this->warn("Service '{$service}' is not configured in config/fuse.php");

            return self::SUCCESS;
        }

        (new CircuitBreaker($service))->forceOpen();

        $this->info("Circuit breaker for {$service} has been manually opened.");

        return self::SUCCESS;
    }
}
