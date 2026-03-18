<?php

namespace Marmanik\AzureTableCache\Exceptions;

use RuntimeException;

class AzureTableException extends RuntimeException
{
    public static function notFound(): static
    {
        return new static('Entity not found.', 404);
    }
}
