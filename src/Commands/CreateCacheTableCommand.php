<?php

namespace Marmanik\AzureTableCache\Commands;

use Illuminate\Console\Command;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use MicrosoftAzure\Storage\Table\TableRestProxy;

class CreateCacheTableCommand extends Command
{
    public $signature = 'azure-table-cache:create-table';

    public $description = 'Create the Azure Storage Table used for caching';

    public function handle(): int
    {
        $config = config('azure-table-cache');
        $table = $config['table'];

        $this->info("Creating Azure Storage Table: {$table}");

        $connectionString = $this->buildConnectionString($config);
        $client = TableRestProxy::createTableService($connectionString);

        try {
            $client->createTable($table);
            $this->info("Table '{$table}' created successfully.");
        } catch (ServiceException $e) {
            // 409 Conflict means the table already exists
            if ($e->getCode() === 409) {
                $this->warn("Table '{$table}' already exists — nothing to do.");

                return self::SUCCESS;
            }

            $this->error('Failed to create table: ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function buildConnectionString(array $config): string
    {
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
    }
}
