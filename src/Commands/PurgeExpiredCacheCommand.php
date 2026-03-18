<?php

namespace Marmanik\AzureTableCache\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Marmanik\AzureTableCache\AzureTableCacheStore;

class PurgeExpiredCacheCommand extends Command
{
    public $signature = 'azure-table-cache:purge-expired';

    public $description = 'Delete expired entries from the Azure Storage Table cache';

    public function handle(): int
    {
        $store = Cache::driver('azure-table')->getStore();

        if (! $store instanceof AzureTableCacheStore) {
            $this->error('The configured cache driver is not AzureTableCacheStore.');

            return self::FAILURE;
        }

        $this->info('Purging expired cache entries...');

        $count = $store->purgeExpired();

        $this->info("Deleted {$count} expired cache " . ($count === 1 ? 'entry' : 'entries') . '.');

        return self::SUCCESS;
    }
}
