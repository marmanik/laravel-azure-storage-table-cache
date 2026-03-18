<?php

namespace Marmanik\AzureTableCache;

use Marmanik\AzureTableCache\Contracts\AzureTableClient;
use Marmanik\AzureTableCache\Data\CacheEntry;
use Marmanik\AzureTableCache\Exceptions\AzureTableException;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use MicrosoftAzure\Storage\Table\Models\DeleteEntityOptions;
use MicrosoftAzure\Storage\Table\Models\EdmType;
use MicrosoftAzure\Storage\Table\Models\Entity;
use MicrosoftAzure\Storage\Table\Models\Filters\QueryStringFilter;
use MicrosoftAzure\Storage\Table\Models\QueryEntitiesOptions;
use MicrosoftAzure\Storage\Table\TableRestProxy;

/**
 * Adapts the microsoft/azure-storage-table SDK to our internal interface,
 * translating SDK types into domain value objects so the store and test
 * layers are completely decoupled from the vendor SDK.
 */
class AzureTableClientAdapter implements AzureTableClient
{
    public function __construct(private readonly TableRestProxy $proxy) {}

    public function getEntity(string $table, string $partitionKey, string $rowKey): CacheEntry
    {
        try {
            $entity = $this->proxy->getEntity($table, $partitionKey, $rowKey)->getEntity();

            return new CacheEntry(
                partitionKey: $entity->getPartitionKey(),
                rowKey: $entity->getRowKey(),
                encodedValue: $entity->getPropertyValue('CacheValue') ?? '',
                expiresAt: (int) ($entity->getPropertyValue('ExpiresAt') ?? '0'),
            );
        } catch (ServiceException $e) {
            throw new AzureTableException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function upsertEntity(string $table, CacheEntry $entry): void
    {
        try {
            $entity = new Entity();
            $entity->setPartitionKey($entry->partitionKey);
            $entity->setRowKey($entry->rowKey);
            $entity->addProperty('CacheValue', EdmType::STRING, $entry->encodedValue);
            $entity->addProperty('ExpiresAt', EdmType::STRING, (string) $entry->expiresAt);

            $this->proxy->insertOrReplaceEntity($table, $entity);
        } catch (ServiceException $e) {
            throw new AzureTableException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function deleteEntity(string $table, string $partitionKey, string $rowKey): void
    {
        try {
            $options = new DeleteEntityOptions();
            $options->setETag('*');
            $this->proxy->deleteEntity($table, $partitionKey, $rowKey, $options);
        } catch (ServiceException $e) {
            throw new AzureTableException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function queryEntities(
        string $table,
        string $filter,
        ?string $nextPartitionKey = null,
        ?string $nextRowKey = null,
    ): array {
        try {
            $options = new QueryEntitiesOptions();
            $options->setFilter(new QueryStringFilter($filter));

            if ($nextPartitionKey !== null) {
                $options->setNextPartitionKey($nextPartitionKey);
            }

            if ($nextRowKey !== null) {
                $options->setNextRowKey($nextRowKey);
            }

            $result = $this->proxy->queryEntities($table, $options);

            $entries = array_map(
                fn (Entity $entity) => new CacheEntry(
                    partitionKey: $entity->getPartitionKey(),
                    rowKey: $entity->getRowKey(),
                    encodedValue: $entity->getPropertyValue('CacheValue') ?? '',
                    expiresAt: (int) ($entity->getPropertyValue('ExpiresAt') ?? '0'),
                ),
                $result->getEntities(),
            );

            return [
                'entries' => $entries,
                'nextPartitionKey' => $result->getNextPartitionKey(),
                'nextRowKey' => $result->getNextRowKey(),
            ];
        } catch (ServiceException $e) {
            throw new AzureTableException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
