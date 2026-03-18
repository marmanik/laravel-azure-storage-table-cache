<?php

namespace Marmanik\AzureTableCache\Data;

/**
 * Domain value object representing a single cache entry.
 * Keeps Azure SDK types out of the store and test layers.
 */
readonly class CacheEntry
{
    public function __construct(
        public string $partitionKey,
        public string $rowKey,
        public string $encodedValue,  // base64(serialize($value))
        public int $expiresAt,        // unix timestamp; 0 = never expires
    ) {}
}
