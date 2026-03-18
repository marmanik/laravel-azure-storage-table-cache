<?php

namespace Marmanik\AzureTableCache;

use Illuminate\Contracts\Cache\Store;
use Marmanik\AzureTableCache\Contracts\AzureTableClient;
use Marmanik\AzureTableCache\Data\CacheEntry;
use Marmanik\AzureTableCache\Exceptions\AzureTableException;

class AzureTableCacheStore implements Store
{
    public function __construct(
        protected readonly AzureTableClient $client,
        protected readonly string $table,
        protected readonly string $partitionKey,
        protected readonly string $prefix = '',
    ) {}

    public function get($key): mixed
    {
        $entry = $this->fetchEntry($key);

        if ($entry === null) {
            return null;
        }

        if ($this->isExpired($entry)) {
            $this->forget($key);

            return null;
        }

        return unserialize(gzuncompress(base64_decode($entry->encodedValue)));
    }

    public function many(array $keys): array
    {
        return array_combine($keys, array_map(fn ($key) => $this->get($key), $keys));
    }

    public function put($key, $value, $seconds): bool
    {
        $entry = new CacheEntry(
            partitionKey: $this->partitionKey,
            rowKey: $this->encodeKey($key),
            encodedValue: base64_encode(gzcompress(serialize($value))),
            expiresAt: $seconds > 0 ? time() + (int) $seconds : 0,
        );

        try {
            $this->client->upsertEntity($this->table, $entry);

            return true;
        } catch (AzureTableException) {
            return false;
        }
    }

    public function putMany(array $values, $seconds): bool
    {
        $results = array_map(
            fn ($key, $value) => $this->put($key, $value, $seconds),
            array_keys($values),
            $values,
        );

        return ! in_array(false, $results, true);
    }

    public function increment($key, $value = 1): int|bool
    {
        $current = $this->get($key);

        if (! is_numeric($current)) {
            return false;
        }

        $new = $current + $value;
        $this->forever($key, $new);

        return $new;
    }

    public function decrement($key, $value = 1): int|bool
    {
        return $this->increment($key, $value * -1);
    }

    public function forever($key, $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function forget($key): bool
    {
        try {
            $this->client->deleteEntity($this->table, $this->partitionKey, $this->encodeKey($key));

            return true;
        } catch (AzureTableException $e) {
            if ($e->getCode() === 404) {
                return true;
            }

            return false;
        }
    }

    public function flush(): bool
    {
        try {
            $nextPartitionKey = null;
            $nextRowKey = null;

            do {
                $page = $this->client->queryEntities(
                    table: $this->table,
                    filter: "PartitionKey eq '{$this->partitionKey}'",
                    nextPartitionKey: $nextPartitionKey,
                    nextRowKey: $nextRowKey,
                );

                foreach ($page['entries'] as $entry) {
                    $this->client->deleteEntity($this->table, $entry->partitionKey, $entry->rowKey);
                }

                $nextPartitionKey = $page['nextPartitionKey'];
                $nextRowKey = $page['nextRowKey'];
            } while ($nextPartitionKey !== null);

            return true;
        } catch (AzureTableException) {
            return false;
        }
    }

    /**
     * Delete all cache entries that have passed their expiry time.
     * Call this from a scheduled command to keep the table lean.
     */
    public function purgeExpired(): int
    {
        $count = 0;
        $now = time();
        $nextPartitionKey = null;
        $nextRowKey = null;

        try {
            do {
                $page = $this->client->queryEntities(
                    table: $this->table,
                    filter: "PartitionKey eq '{$this->partitionKey}'",
                    nextPartitionKey: $nextPartitionKey,
                    nextRowKey: $nextRowKey,
                );

                foreach ($page['entries'] as $entry) {
                    if ($entry->expiresAt !== 0 && $entry->expiresAt < $now) {
                        $this->client->deleteEntity($this->table, $entry->partitionKey, $entry->rowKey);
                        $count++;
                    }
                }

                $nextPartitionKey = $page['nextPartitionKey'];
                $nextRowKey = $page['nextRowKey'];
            } while ($nextPartitionKey !== null);
        } catch (AzureTableException) {
            // Best-effort — don't break callers
        }

        return $count;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    protected function fetchEntry(string $key): ?CacheEntry
    {
        try {
            return $this->client->getEntity($this->table, $this->partitionKey, $this->encodeKey($key));
        } catch (AzureTableException $e) {
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }
    }

    protected function isExpired(CacheEntry $entry): bool
    {
        return $entry->expiresAt !== 0 && $entry->expiresAt < time();
    }

    /**
     * Encode a cache key to a valid Azure Table RowKey.
     *
     * Azure RowKeys cannot contain: / \ # ? and control characters.
     * We use URL-safe base64 (no padding) to guarantee a clean string.
     */
    protected function encodeKey(string $key): string
    {
        return rtrim(strtr(base64_encode($this->prefix . $key), '+/', '-_'), '=');
    }
}
