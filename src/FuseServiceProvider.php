<?php

namespace Cline\Fuse;

use Cline\Fuse\Commands\FuseCloseCommand;
use Cline\Fuse\Commands\FuseOpenCommand;
use Cline\Fuse\Commands\FuseResetCommand;
use Cline\Fuse\Commands\FuseStatusCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Laravel package service provider for Fuse.
 *
 * Registers the package config and the operational artisan commands used to
 * inspect and override circuit breaker state. Runtime breaker instances are
 * created on demand, so the provider's primary job is to expose package
 * configuration and operator tooling to the host application.
 */
class FuseServiceProvider extends PackageServiceProvider
{
    /**
     * Configure the package manifest consumed by Laravel Package Tools.
     *
     * Fuse ships a config file and a small command surface for observing and
     * manually steering breaker state during incidents or recovery testing.
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('fuse')
            ->hasConfigFile()
            ->hasCommands([
                FuseStatusCommand::class,
                FuseResetCommand::class,
                FuseOpenCommand::class,
                FuseCloseCommand::class,
            ]);
    }
}
