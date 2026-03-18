<?php

use Marmanik\AzureTableCache\AzureTableCacheLock;
use Marmanik\AzureTableCache\Contracts\AzureTableClient;
use Marmanik\AzureTableCache\Data\CacheEntry;
use Marmanik\AzureTableCache\Exceptions\AzureTableException;

function makeLock(AzureTableClient $client, string $name = 'invoice-42', int $seconds = 10): AzureTableCacheLock
{
    return new AzureTableCacheLock(
        client: $client,
        table: 'cache',
        partitionKey: 'cache',
        name: $name,
        seconds: $seconds,
        owner: 'test-owner',
    );
}

function makeLockEntry(string $owner = 'test-owner', int $expiresAt = 0): CacheEntry
{
    return new CacheEntry(
        partitionKey: 'cache',
        rowKey: 'lock:invoice-42',
        encodedValue: base64_encode(gzcompress(serialize($owner))),
        expiresAt: $expiresAt,
    );
}

// --- acquire ---

it('acquires a lock when no entity exists', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('insertEntity')->once();

    expect(makeLock($client)->acquire())->toBeTrue();
});

it('returns false when the lock is already held', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('insertEntity')->once()->andThrow(new AzureTableException('Conflict', 409));
    $client->shouldReceive('getEntity')->once()->andReturn(makeLockEntry('other-owner', time() + 60));

    expect(makeLock($client)->acquire())->toBeFalse();
});

it('acquires a lock by overwriting an expired one', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('insertEntity')->once()->andThrow(new AzureTableException('Conflict', 409));
    $client->shouldReceive('getEntity')->once()->andReturn(makeLockEntry('old-owner', time() - 10));
    $client->shouldReceive('upsertEntity')->once();

    expect(makeLock($client)->acquire())->toBeTrue();
});

it('returns false on unexpected insert error', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('insertEntity')->once()->andThrow(new AzureTableException('Server error', 500));

    expect(makeLock($client)->acquire())->toBeFalse();
});

// --- release ---

it('releases a lock owned by this instance', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('getEntity')->once()->andReturn(makeLockEntry('test-owner'));
    $client->shouldReceive('deleteEntity')->once();

    expect(makeLock($client)->release())->toBeTrue();
});

it('does not release a lock owned by another instance', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('getEntity')->once()->andReturn(makeLockEntry('other-owner'));

    expect(makeLock($client)->release())->toBeFalse();
});

it('returns false when releasing a lock that no longer exists', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('getEntity')->once()->andThrow(AzureTableException::notFound());

    expect(makeLock($client)->release())->toBeFalse();
});

// --- forceRelease ---

it('force releases a lock regardless of owner', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('deleteEntity')->once();

    makeLock($client)->forceRelease();

    // No assertion needed — Mockery verifies deleteEntity was called
    expect(true)->toBeTrue();
});

it('does not throw when force releasing a lock that does not exist', function () {
    $client = Mockery::mock(AzureTableClient::class);
    $client->shouldReceive('deleteEntity')->once()->andThrow(AzureTableException::notFound());

    makeLock($client)->forceRelease(); // Should not throw

    expect(true)->toBeTrue();
});
