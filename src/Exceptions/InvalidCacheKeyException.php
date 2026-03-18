<?php

namespace Marmanik\AzureTableCache\Exceptions;

use InvalidArgumentException;

class InvalidCacheKeyException extends InvalidArgumentException
{
    public static function forbiddenCharacters(string $key): static
    {
        return new static(
            "Cache key \"{$key}\" contains characters not allowed in an Azure Table RowKey. ".
            'Forbidden characters: / \\ # ? and control characters (0x00–0x1F, 0x7F).'
        );
    }

    public static function tooLong(string $key, int $max = 1024): static
    {
        return new static(
            "Cache key \"{$key}\" exceeds the Azure Table RowKey limit of {$max} bytes."
        );
    }
}
