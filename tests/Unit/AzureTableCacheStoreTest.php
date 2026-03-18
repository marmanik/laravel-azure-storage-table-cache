<?php

use Marmanik\AzureTableCache\AzureTableCacheStore;
use Marmanik\AzureTableCache\Contracts\AzureTableClient;
use Marmanik\AzureTableCache\Data\CacheEntry;
use Marmanik\AzureTableCache\Exceptions\AzureTableException;
use Marmanik\AzureTableCache\Exceptions\InvalidCacheKeyException;

function makeEntry(mixed $value, int $expiresAt = 0): CacheEntry
{
    return new CacheEntry(
        partitionKey: 'cache',
        rowKey: 'somekey',
        encodedValue: base64_encode(gzcompress(serialize($value))),
        expiresAt: $expiresAt,
    );
}

function makeStore(AzureTableClient $client): AzureTableCacheStore
{
    return new AzureTableCacheStore(
        client: $client,
        table: 'cache',
        partitionKey: 'cache',
        prefix: '',
    );
}

it('returns null for a missing key', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('getEntity')
        ->once()
        ->andThrow(AzureTableException::notFound());

    expect(makeStore($client)->get('missing'))->toBeNull();
});

it('returns a stored value', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('getEntity')->once()->andReturn(makeEntry('hello world'));

    expect(makeStore($client)->get('key'))->toBe('hello world');
});

it('returns null and deletes an expired entry', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('getEntity')->once()->andReturn(makeEntry('stale', time() - 100));
    $client->shouldReceive('deleteEntity')->once();

    expect(makeStore($client)->get('expired'))->toBeNull();
});

it('stores a value with expiry', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('upsertEntity')->once();

    expect(makeStore($client)->put('key', 'value', 60))->toBeTrue();
});

it('stores a value forever when seconds is 0', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('upsertEntity')
        ->once()
        ->withArgs(fn (string $table, CacheEntry $entry) => $entry->expiresAt === 0);

    expect(makeStore($client)->forever('key', 'value'))->toBeTrue();
});

it('increments a numeric value', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('getEntity')->once()->andReturn(makeEntry(10));
    $client->shouldReceive('upsertEntity')->once();

    expect(makeStore($client)->increment('counter', 5))->toBe(15);
});

it('preserves the original TTL after increment', function () {
    $expiresAt = time() + 86400; // 1 day from now
    $entry = makeEntry(0, $expiresAt);

    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('getEntity')->once()->andReturn($entry);
    $client->shouldReceive('upsertEntity')
        ->once()
        ->withArgs(fn (string $table, CacheEntry $updated) => $updated->expiresAt === $expiresAt);

    expect(makeStore($client)->increment('visits'))->toBe(1);
});

it('preserves the original TTL after decrement', function () {
    $expiresAt = time() + 86400;
    $entry = makeEntry(10, $expiresAt);

    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('getEntity')->once()->andReturn($entry);
    $client->shouldReceive('upsertEntity')
        ->once()
        ->withArgs(fn (string $table, CacheEntry $updated) => $updated->expiresAt === $expiresAt);

    expect(makeStore($client)->decrement('visits', 3))->toBe(7);
});

it('returns false when incrementing a non-numeric value', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('getEntity')->once()->andReturn(makeEntry('not-a-number'));

    expect(makeStore($client)->increment('key'))->toBeFalse();
});

it('deletes a key', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('deleteEntity')->once();

    expect(makeStore($client)->forget('key'))->toBeTrue();
});

it('returns true when forgetting a key that does not exist', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('deleteEntity')->once()->andThrow(AzureTableException::notFound());

    expect(makeStore($client)->forget('missing'))->toBeTrue();
});

it('accepts a valid cache key', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('getEntity')->once()->andThrow(AzureTableException::notFound());

    expect(makeStore($client)->get('valid-key:user.42'))->toBeNull();
});

it('throws on a key containing a forward slash', function () {
    makeStore(Mockery::mock(AzureTableClient::class))->get('user/42');
})->throws(InvalidCacheKeyException::class);

it('throws on a key containing a backslash', function () {
    makeStore(Mockery::mock(AzureTableClient::class))->get('user\\42');
})->throws(InvalidCacheKeyException::class);

it('throws on a key containing a hash', function () {
    makeStore(Mockery::mock(AzureTableClient::class))->get('tags#featured');
})->throws(InvalidCacheKeyException::class);

it('throws on a key containing a question mark', function () {
    makeStore(Mockery::mock(AzureTableClient::class))->get('filter?page=1');
})->throws(InvalidCacheKeyException::class);

it('throws on a key containing a control character', function () {
    makeStore(Mockery::mock(AzureTableClient::class))->get("bad\x01key");
})->throws(InvalidCacheKeyException::class);

it('throws on a key exceeding 1024 bytes', function () {
    makeStore(Mockery::mock(AzureTableClient::class))->get(str_repeat('a', 1025));
})->throws(InvalidCacheKeyException::class);

it('round-trips an array of objects', function () {
    $objects = array_map(
        fn ($i) => (object) ['id' => $i, 'name' => "Item {$i}", 'tags' => ['a', 'b', 'c']],
        range(1, 50),
    );

    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('getEntity')->once()->andReturn(makeEntry($objects));

    $result = makeStore($client)->get('objects');

    expect($result)->toHaveCount(50)
        ->and($result[0]->id)->toBe(1)
        ->and($result[49]->name)->toBe('Item 50');
});

it('compresses values so large payloads fit within the 64 KB Azure property limit', function () {
    // 500 objects with repeated string data — compresses dramatically
    $largeCollection = array_map(
        fn ($i) => ['id' => $i, 'description' => str_repeat('Azure Storage Table cache ', 10)],
        range(1, 500),
    );

    $encoded = base64_encode(gzcompress(serialize($largeCollection)));

    // Raw serialized size
    $rawSize = strlen(base64_encode(serialize($largeCollection)));

    expect(strlen($encoded))->toBeLessThan(65536)  // fits in Azure's 64 KB limit
        ->and(strlen($encoded))->toBeLessThan($rawSize); // compression helped
});
