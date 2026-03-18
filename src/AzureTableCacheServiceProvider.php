<?php

namespace Marmanik\AzureTableCache;

use Illuminate\Support\Facades\Cache;
use Marmanik\AzureTableCache\Commands\CreateCacheTableCommand;
use Marmanik\AzureTableCache\Commands\PurgeExpiredCacheCommand;
use MicrosoftAzure\Storage\Table\TableRestProxy;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AzureTableCacheServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('azure-table-cache')
            ->hasConfigFile()
            ->hasCommands([
                CreateCacheTableCommand::class,
                PurgeExpiredCacheCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        // Build the connection string outside the closure — Cache::extend() rebinds
        // the closure to CacheManager, so $this would no longer refer to this provider.
        $buildConnectionString = static function (array $config): string {
            if (! empty($config['endpoint'])) {
                return sprintf(
                    'TableEndpoint=%s;AccountName=%s;AccountKey=%s;',
                    $config['endpoint'],
                    $config['account_name'],
                    $config['account_key'],
                );
            }

            return sprintf(
                'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s;',
                $config['account_name'],
                $config['account_key'],
            );
        };

        Cache::extend('azure-table', function ($app) use ($buildConnectionString) {
            $config = $app['config']['azure-table-cache'];

            $client = new AzureTableClientAdapter(
                TableRestProxy::createTableService($buildConnectionString($config)),
            );

            $store = new AzureTableCacheStore(
                client: $client,
                table: $config['table'],
                partitionKey: $config['partition_key'],
                prefix: $config['prefix'] ?? '',
            );

            return $app['cache']->repository($store);
        });
    }
}
