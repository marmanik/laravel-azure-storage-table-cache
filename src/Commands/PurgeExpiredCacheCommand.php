<?php

namespace Marmanik\AzureTableCache\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Marmanik\AzureTableCache\AzureTableCacheStore;

class PurgeExpiredCacheCommand extends Command
{
    public $signature = 'azure-table-cache:purge-expired
                         {--store=azure-table : The cache store name as defined in config/cache.php}';

    public $description = 'Delete expired entries from the Azure Storage Table cache';

    public function handle(): int
    {
        $storeName = $this->option('store');
        $store = Cache::driver($storeName)->getStore();

        if (! $store instanceof AzureTableCacheStore) {
            $this->error("The \"{$storeName}\" cache store is not an AzureTableCacheStore.");

            return self::FAILURE;
        }

        $this->info('Purging expired cache entries...');

        $count = $store->purgeExpired();

        $this->info("Deleted {$count} expired cache " . ($count === 1 ? 'entry' : 'entries') . '.');

        return self::SUCCESS;
    }
}
