<?php

// config for marmanik/laravel-azure-table-cache
return [

    /*
    |--------------------------------------------------------------------------
    | Azure Storage Account Credentials
    |--------------------------------------------------------------------------
    |
    | The name and key of your Azure Storage account. These can be found in
    | the Azure Portal under your storage account's "Access keys" blade.
    |
    */

    'account_name' => env('AZURE_STORAGE_ACCOUNT_NAME'),

    'account_key' => env('AZURE_STORAGE_ACCOUNT_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Custom Table Endpoint
    |--------------------------------------------------------------------------
    |
    | Override the default table endpoint. Useful for local development with
    | Azurite: e.g. "http://127.0.0.1:10002/devstoreaccount1"
    |
    */

    'endpoint' => env('AZURE_STORAGE_TABLE_ENDPOINT'),

    /*
    |--------------------------------------------------------------------------
    | Table Name
    |--------------------------------------------------------------------------
    |
    | The Azure Storage Table used to store cache entries. The table must
    | exist before the driver is used — run the artisan command to create it:
    |
    |   php artisan azure-table-cache:create-table
    |
    */

    'table' => env('AZURE_CACHE_TABLE', 'cache'),

    /*
    |--------------------------------------------------------------------------
    | Partition Key
    |--------------------------------------------------------------------------
    |
    | All cache entries are stored under this partition key. You can change
    | this to separate cache entries from other data in the same table, or to
    | run multiple apps against the same table without collisions.
    |
    */

    'partition_key' => env('AZURE_CACHE_PARTITION_KEY', 'cache'),

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | A prefix prepended to every cache key before it is encoded and stored.
    | Useful when multiple applications share the same table and partition.
    |
    */

    'prefix' => env('AZURE_CACHE_PREFIX', ''),

];
