<?php

declare(strict_types=1);

namespace Vitis\RestDB\OpenApi;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class OpenApiServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('restdb-openapi')
            ->hasCommands([
                Commands\MakeModelsCommand::class,
            ]);
    }
}
