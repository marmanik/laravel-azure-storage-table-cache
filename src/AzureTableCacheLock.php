<?php

namespace Marmanik\AzureTableCache;

use Illuminate\Cache\Lock;
use Marmanik\AzureTableCache\Contracts\AzureTableClient;
use Marmanik\AzureTableCache\Data\CacheEntry;
use Marmanik\AzureTableCache\Exceptions\AzureTableException;

class AzureTableCacheLock extends Lock
{
    public function __construct(
        private readonly AzureTableClient $client,
        private readonly string $table,
        private readonly string $partitionKey,
        string $name,
        int $seconds,
        ?string $owner = null,
    ) {
        parent::__construct($name, $seconds, $owner);
    }

    /**
     * Attempt to acquire the lock.
     *
     * Uses insertEntity which is atomic in Azure Table Storage — only one
     * concurrent caller succeeds; the rest receive a 409 Conflict.
     * If an existing lock has expired it is unconditionally overwritten.
     */
    public function acquire(): bool
    {
        $entry = $this->lockEntry();

        try {
            $this->client->insertEntity($this->table, $entry);

            return true;
        } catch (AzureTableException $e) {
            if ($e->getCode() !== 409) {
                return false;
            }
        }

        // Lock entity already exists — check whether it has expired.
        try {
            $existing = $this->client->getEntity($this->table, $this->partitionKey, $this->lockRowKey());

            if ($existing->expiresAt !== 0 && $existing->expiresAt < time()) {
                // Expired lock — overwrite it with ours.
                $this->client->upsertEntity($this->table, $entry);

                return true;
            }
        } catch (AzureTableException) {
            // Entity disappeared between our insert attempt and the get — another
            // process grabbed it; report not acquired.
        }

        return false;
    }

    /**
     * Release the lock if this instance owns it.
     */
    public function release(): bool
    {
        try {
            $existing = $this->client->getEntity($this->table, $this->partitionKey, $this->lockRowKey());
            $currentOwner = unserialize(gzuncompress(base64_decode($existing->encodedValue)));

            if ($currentOwner !== $this->owner) {
                return false;
            }

            $this->client->deleteEntity($this->table, $this->partitionKey, $this->lockRowKey());

            return true;
        } catch (AzureTableException) {
            return false;
        }
    }

    /**
     * Return the owner of the lock as currently stored, or null if not held.
     */
    public function getCurrentOwner(): ?string
    {
        try {
            $entry = $this->client->getEntity($this->table, $this->partitionKey, $this->lockRowKey());

            if ($entry->expiresAt !== 0 && $entry->expiresAt < time()) {
                return null;
            }

            return unserialize(gzuncompress(base64_decode($entry->encodedValue)));
        } catch (AzureTableException) {
            return null;
        }
    }

    /**
     * Release the lock regardless of ownership.
     */
    public function forceRelease(): void
    {
        try {
            $this->client->deleteEntity($this->table, $this->partitionKey, $this->lockRowKey());
        } catch (AzureTableException) {
            // Already gone — nothing to do.
        }
    }

    private function lockEntry(): CacheEntry
    {
        return new CacheEntry(
            partitionKey: $this->partitionKey,
            rowKey: $this->lockRowKey(),
            encodedValue: base64_encode(gzcompress(serialize($this->owner))),
            expiresAt: $this->seconds > 0 ? time() + $this->seconds : 0,
        );
    }

    private function lockRowKey(): string
    {
        return 'lock:' . $this->name;
    }
}
