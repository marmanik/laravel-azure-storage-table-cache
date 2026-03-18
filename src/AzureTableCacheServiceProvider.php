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
            ->hasCommands([
                CreateCacheTableCommand::class,
                PurgeExpiredCacheCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        // Laravel passes the store's config array from cache.php as the second argument,
        // matching the same pattern used by the built-in DynamoDB driver.
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

        Cache::extend('azure-table', function ($app, $config) use ($buildConnectionString) {
            $client = new AzureTableClientAdapter(
                TableRestProxy::createTableService($buildConnectionString($config)),
            );

            $store = new AzureTableCacheStore(
                client: $client,
                table: $config['table'] ?? 'cache',
                partitionKey: $config['partition_key'] ?? 'cache',
                prefix: $config['prefix'] ?? '',
            );

            return $app['cache']->repository($store);
        });
    }
}
