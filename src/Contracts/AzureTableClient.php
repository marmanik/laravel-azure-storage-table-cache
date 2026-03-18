<?php

namespace Marmanik\AzureTableCache\Contracts;

use Marmanik\AzureTableCache\Data\CacheEntry;
use Marmanik\AzureTableCache\Exceptions\AzureTableException;

interface AzureTableClient
{
    /**
     * @throws AzureTableException (code 404 when not found)
     */
    public function getEntity(string $table, string $partitionKey, string $rowKey): CacheEntry;

    public function upsertEntity(string $table, CacheEntry $entry): void;

    public function deleteEntity(string $table, string $partitionKey, string $rowKey): void;

    /**
     * @return array{entries: CacheEntry[], nextPartitionKey: ?string, nextRowKey: ?string}
     */
    public function queryEntities(
        string $table,
        string $filter,
        ?string $nextPartitionKey = null,
        ?string $nextRowKey = null,
    ): array;
}
