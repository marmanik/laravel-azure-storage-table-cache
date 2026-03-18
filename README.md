# Laravel Azure Table Cache

A Laravel cache driver backed by [Azure Storage Tables](https://learn.microsoft.com/en-us/azure/storage/tables/table-storage-overview). Drop-in replacement for Redis or database cache — no extra infrastructure required if you are already on Azure.

---

## How it works

Each cache entry is stored as a row in an Azure Storage Table with three columns:

| Column | Type | Description |
|---|---|---|
| `PartitionKey` | string | Configurable (default: `cache`) |
| `RowKey` | string | URL-safe base64 of the cache key |
| `CacheValue` | string | `base64(serialize($value))` |
| `ExpiresAt` | string | Unix timestamp; `0` = never expires |

> Azure Storage Tables has no native TTL. Expiry is enforced on read, and a provided Artisan command cleans up stale entries on a schedule.

---

## Requirements

- PHP **8.2+**
- Laravel **11** or **12**
- An Azure Storage account (or [Azurite](https://learn.microsoft.com/en-us/azure/storage/common/storage-use-azurite) for local dev)

---

## Installation

```bash
composer require marmanik/laravel-azure-table-cache
```

The service provider is auto-discovered — no manual registration needed.

---

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag="azure-table-cache-config"
```

This creates `config/azure-table-cache.php`:

```php
return [
    'account_name'  => env('AZURE_STORAGE_ACCOUNT_NAME'),
    'account_key'   => env('AZURE_STORAGE_ACCOUNT_KEY'),
    'endpoint'      => env('AZURE_STORAGE_TABLE_ENDPOINT'), // optional — for Azurite
    'table'         => env('AZURE_CACHE_TABLE', 'cache'),
    'partition_key' => env('AZURE_CACHE_PARTITION_KEY', 'cache'),
    'prefix'        => env('AZURE_CACHE_PREFIX', ''),
];
```

Add these variables to your `.env`:

```env
AZURE_STORAGE_ACCOUNT_NAME=your_account_name
AZURE_STORAGE_ACCOUNT_KEY=your_base64_account_key
AZURE_CACHE_TABLE=cache
```

---

## Register the driver

Open `config/cache.php` and add the store:

```php
'stores' => [

    // ... other stores

    'azure-table' => [
        'driver' => 'azure-table',
    ],

],
```

To use it as the **default** cache driver, update your `.env`:

```env
CACHE_STORE=azure-table
```

Or set it as default in `config/cache.php`:

```php
'default' => env('CACHE_STORE', 'azure-table'),
```

---

## Create the Azure Table

Before the driver can be used the table must exist. Run the provided Artisan command once — during deployment or as part of your setup scripts:

```bash
php artisan azure-table-cache:create-table
```

The command is idempotent: running it against an existing table is safe.

---

## Usage

Once the driver is registered, use it exactly like any other Laravel cache store.

### Via the default driver

```php
use Illuminate\Support\Facades\Cache;

// Store a value for 10 minutes
Cache::put('user:42:profile', $profile, now()->addMinutes(10));

// Retrieve it
$profile = Cache::get('user:42:profile');

// Store forever
Cache::forever('settings:global', $settings);

// Delete
Cache::forget('user:42:profile');

// Flush all entries in the partition
Cache::flush();
```

### Via an explicit driver

```php
Cache::driver('azure-table')->put('key', 'value', 300);

$value = Cache::driver('azure-table')->get('key');
```

### Remember pattern

```php
$posts = Cache::remember('posts:featured', now()->addHour(), function () {
    return Post::featured()->get();
});
```

### Atomic increment / decrement

```php
Cache::put('visits', 0, now()->addDay());

Cache::increment('visits');       // 1
Cache::increment('visits', 10);   // 11
Cache::decrement('visits', 3);    // 8
```

### Checking existence

```php
if (Cache::has('feature:dark-mode')) {
    // ...
}
```

### Storing multiple values at once

```php
Cache::putMany([
    'user:1' => $user1,
    'user:2' => $user2,
], now()->addMinutes(30));

$users = Cache::many(['user:1', 'user:2']);
```

---

## Artisan commands

### Create the storage table

```bash
php artisan azure-table-cache:create-table
```

Creates the Azure Storage Table configured in `AZURE_CACHE_TABLE`. Safe to run multiple times.

### Purge expired entries

Azure Tables has no automatic expiry. Entries are ignored on read once expired, but they remain in the table until deleted. Run this command periodically to keep the table lean:

```bash
php artisan azure-table-cache:purge-expired
```

Schedule it in `routes/console.php` (Laravel 11+):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('azure-table-cache:purge-expired')->daily();
```

Or in `app/Console/Kernel.php` (Laravel 10):

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('azure-table-cache:purge-expired')->daily();
}
```

---

## Local development with Azurite

[Azurite](https://learn.microsoft.com/en-us/azure/storage/common/storage-use-azurite) is the official Azure Storage emulator. Run it with Docker:

```bash
docker run -p 10002:10002 mcr.microsoft.com/azure-storage/azurite azurite-table
```

Then set in your `.env`:

```env
AZURE_STORAGE_ACCOUNT_NAME=devstoreaccount1
AZURE_STORAGE_ACCOUNT_KEY=Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==
AZURE_STORAGE_TABLE_ENDPOINT=http://127.0.0.1:10002/devstoreaccount1
```

> The account name and key above are the well-known Azurite defaults — they are not real credentials.

---

## Multiple apps sharing one table

Use a unique `partition_key` or `prefix` per app to avoid key collisions:

```env
# App 1
AZURE_CACHE_PARTITION_KEY=app1
AZURE_CACHE_PREFIX=app1_

# App 2
AZURE_CACHE_PARTITION_KEY=app2
AZURE_CACHE_PREFIX=app2_
```

---

## Testing

Run the test suite:

```bash
composer test
```

Run static analysis:

```bash
composer analyse
```

Format code:

```bash
composer format
```

---

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for recent changes.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
