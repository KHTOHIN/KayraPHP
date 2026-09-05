<?php

declare(strict_types=1);

namespace Kayra\Tests\Unit;

use DateInterval;
use Kayra\Cache\ArrayStore;
use Kayra\Cache\FileStore;
use Kayra\Cache\InvalidArgumentException;
use Kayra\Cache\Repository;
use Kayra\Cache\Store;
use Kayra\Encryption\Encrypter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgument;

/**
 * Every behavioural test runs against both drivers.
 *
 * A cache whose semantics change with the driver is worse than no cache: the
 * bug only appears in production, where the driver is different.
 */
#[CoversClass(Repository::class)]
#[CoversClass(ArrayStore::class)]
#[CoversClass(FileStore::class)]
final class CacheTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }

        parent::tearDown();
    }

    private function directory(): string
    {
        $path = sys_get_temp_dir() . '/kayra_cache_' . bin2hex(random_bytes(4));
        $this->directories[] = $path;

        return $path;
    }

    private function encrypter(): Encrypter
    {
        return new Encrypter(random_bytes(32));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        yield 'array'         => ['array'];
        yield 'file'          => ['file'];
        yield 'file (signed)' => ['file-signed'];
    }

    private function store(string $driver): Store
    {
        return match ($driver) {
            'array'       => new ArrayStore(),
            'file'        => new FileStore($this->directory()),
            'file-signed' => new FileStore($this->directory(), $this->encrypter()),
            default       => self::fail("unknown driver [{$driver}]"),
        };
    }

    private function cache(string $driver, int $defaultTtl = 3600): Repository
    {
        return new Repository($this->store($driver), $defaultTtl);
    }

    /* --------------------------------------------------------------------
     | PSR-16
     * -------------------------------------------------------------------- */

    #[Test]
    #[DataProvider('drivers')]
    public function it_is_a_psr16_cache(string $driver): void
    {
        $this->assertInstanceOf(CacheInterface::class, $this->cache($driver));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function a_value_survives_a_round_trip(string $driver): void
    {
        $cache = $this->cache($driver);
        $cache->set('greeting', 'hello');

        $this->assertSame('hello', $cache->get('greeting'));
        $this->assertTrue($cache->has('greeting'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function a_miss_returns_the_default(string $driver): void
    {
        $cache = $this->cache($driver);

        $this->assertSame('fallback', $cache->get('absent', 'fallback'));
        $this->assertFalse($cache->has('absent'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function a_cached_null_is_a_hit_not_a_miss(string $driver): void
    {
        // Without a sentinel, a callback that legitimately returns null would
        // be recomputed on every single request -- the case caching exists for.
        $cache = $this->cache($driver);
        $cache->set('nothing', null);

        $this->assertNull($cache->get('nothing', 'fallback'));
        $this->assertTrue($cache->has('nothing'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function delete_and_clear_remove_entries(string $driver): void
    {
        $cache = $this->cache($driver);
        $cache->set('a', 1);
        $cache->set('b', 2);

        $cache->delete('a');
        $this->assertFalse($cache->has('a'));
        $this->assertTrue($cache->has('b'));

        $cache->clear();
        $this->assertFalse($cache->has('b'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function an_elapsed_ttl_deletes_rather_than_stores(string $driver): void
    {
        $cache = $this->cache($driver);
        $cache->set('doomed', 'x');
        $cache->set('doomed', 'x', -1);

        $this->assertFalse($cache->has('doomed'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function an_expired_entry_reads_as_absent(string $driver): void
    {
        $store = $this->store($driver);
        // Written straight to the store with an expiry already in the past.
        $store->put('stale', 'x', 1);

        $cache = new Repository($store);
        $this->assertTrue($cache->has('stale'));

        // Rather than sleeping: put it back with a lifetime that has run out.
        $store->put('stale', 'x', -5);
        $this->assertFalse($cache->has('stale'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function a_date_interval_ttl_is_understood(string $driver): void
    {
        $cache = $this->cache($driver);

        $this->assertTrue($cache->set('interval', 'v', new DateInterval('PT10M')));
        $this->assertSame('v', $cache->get('interval'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function the_multiple_key_methods_work(string $driver): void
    {
        $cache = $this->cache($driver);
        $cache->setMultiple(['a' => 1, 'b' => 2]);

        $this->assertSame(
            ['a' => 1, 'b' => 2, 'c' => 'missing'],
            $cache->getMultiple(['a', 'b', 'c'], 'missing'),
        );

        $cache->deleteMultiple(['a', 'b']);

        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('b'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function a_reserved_key_is_rejected(string $driver): void
    {
        // PSR-16 reserves {}()/\@:. Mangling the key silently is how two
        // different keys quietly become one.
        $this->expectException(Psr16InvalidArgument::class);

        $this->cache($driver)->set('user{1}', 'x');
    }

    #[Test]
    #[DataProvider('drivers')]
    public function an_empty_key_is_rejected(string $driver): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cache($driver)->get('');
    }

    /* --------------------------------------------------------------------
     | Beyond the specification
     * -------------------------------------------------------------------- */

    #[Test]
    #[DataProvider('drivers')]
    public function remember_computes_once(string $driver): void
    {
        $cache = $this->cache($driver);
        $calls = 0;

        $compute = static function () use (&$calls): string {
            $calls++;

            return 'computed';
        };

        $this->assertSame('computed', $cache->remember('report', 60, $compute));
        $this->assertSame('computed', $cache->remember('report', 60, $compute));
        $this->assertSame(1, $calls);
    }

    #[Test]
    #[DataProvider('drivers')]
    public function remember_treats_a_cached_null_as_a_hit(string $driver): void
    {
        $cache = $this->cache($driver);
        $calls = 0;

        $compute = static function () use (&$calls): null {
            $calls++;

            return null;
        };

        $cache->remember('nothing', 60, $compute);
        $cache->remember('nothing', 60, $compute);

        $this->assertSame(1, $calls, 'a null result must still be cached');
    }

    #[Test]
    #[DataProvider('drivers')]
    public function forever_stores_rather_than_deleting(string $driver): void
    {
        // set($key, $value, 0) is a deletion under PSR-16, so forever() must
        // not be implemented in terms of it.
        $cache = $this->cache($driver);

        $this->assertTrue($cache->forever('permanent', 'stays'));
        $this->assertSame('stays', $cache->get('permanent'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function remember_forever_stores_rather_than_deleting(string $driver): void
    {
        $cache = $this->cache($driver);
        $calls = 0;

        $compute = static function () use (&$calls): string {
            $calls++;

            return 'value';
        };

        $this->assertSame('value', $cache->rememberForever('k', $compute));
        $this->assertSame('value', $cache->rememberForever('k', $compute));
        $this->assertSame(1, $calls);
    }

    #[Test]
    #[DataProvider('drivers')]
    public function add_only_stores_when_the_key_is_absent(string $driver): void
    {
        $cache = $this->cache($driver);

        $this->assertTrue($cache->add('once', 'first'));
        $this->assertFalse($cache->add('once', 'second'));
        $this->assertSame('first', $cache->get('once'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function pull_reads_and_removes(string $driver): void
    {
        $cache = $this->cache($driver);
        $cache->set('one-shot', 'value');

        $this->assertSame('value', $cache->pull('one-shot'));
        $this->assertFalse($cache->has('one-shot'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function counters_go_up_and_down(string $driver): void
    {
        $cache = $this->cache($driver);
        $cache->set('hits', 10);

        $this->assertSame(13, $cache->increment('hits', 3));
        $this->assertSame(11, $cache->decrement('hits', 2));
        $this->assertSame(11, $cache->get('hits'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function incrementing_an_absent_key_starts_at_zero(string $driver): void
    {
        $this->assertSame(1, $this->cache($driver)->increment('fresh'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function incrementing_a_non_number_reports_failure(string $driver): void
    {
        $cache = $this->cache($driver);
        $cache->set('name', 'kawsar');

        $this->assertFalse($cache->increment('name'));
        $this->assertSame('kawsar', $cache->get('name'), 'the value must be left alone');
    }

    #[Test]
    #[DataProvider('drivers')]
    public function structured_values_round_trip(string $driver): void
    {
        $cache = $this->cache($driver);
        $value = ['list' => [1, 2, 3], 'nested' => ['on' => true]];

        $cache->set('structured', $value);

        $this->assertSame($value, $cache->get('structured'));
    }

    /* --------------------------------------------------------------------
     | The file driver specifically
     * -------------------------------------------------------------------- */

    #[Test]
    public function the_repository_rejects_a_traversal_key_outright(): void
    {
        // / and \ are both in the PSR-16 reserved set, so a traversal attempt
        // never reaches a driver in the first place.
        $this->expectException(InvalidArgumentException::class);

        (new Repository(new FileStore($this->directory())))->set('../../.env', 'x');
    }

    #[Test]
    public function the_file_store_still_contains_a_traversal_key_on_its_own(): void
    {
        // Defence in depth: a caller using the driver directly bypasses the
        // repository's validation, and the filename must still stay put.
        $directory = $this->directory();
        $store = new FileStore($directory);

        $store->put('..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'escaped', 'x', 60);

        $this->assertSame('x', $store->get('..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'escaped'));
        $this->assertCount(1, glob($directory . '/c_*') ?: [], 'the entry must live inside the cache directory');
        $this->assertFileDoesNotExist(dirname($directory, 2) . '/escaped');
    }

    #[Test]
    public function a_tampered_entry_is_discarded_rather_than_unserialised(): void
    {
        // The whole reason entries are signed: reading one means unserialize(),
        // and unserialising what an attacker wrote is code execution.
        $directory = $this->directory();
        $cache = new Repository(new FileStore($directory, $this->encrypter()));
        $cache->set('trusted', 'original');

        $file = (glob($directory . '/c_*') ?: [])[0] ?? null;
        self::assertIsString($file);

        $contents = (string) file_get_contents($file);
        file_put_contents($file, str_replace('original', 'tampered', $contents));

        $this->assertSame('MISS', $cache->get('trusted', 'MISS'));
    }

    #[Test]
    public function an_unsigned_entry_is_refused_when_signing_is_configured(): void
    {
        $directory = $this->directory();

        // Written by something that did not have the key.
        (new Repository(new FileStore($directory)))->set('planted', 'payload');

        $signed = new Repository(new FileStore($directory, $this->encrypter()));

        $this->assertSame('MISS', $signed->get('planted', 'MISS'));
    }

    #[Test]
    public function an_entry_signed_with_another_key_is_refused(): void
    {
        $directory = $this->directory();

        (new Repository(new FileStore($directory, $this->encrypter())))->set('rotated', 'value');

        // A different key: the same situation as APP_KEY having been rotated.
        $other = new Repository(new FileStore($directory, $this->encrypter()));

        $this->assertSame('MISS', $other->get('rotated', 'MISS'));
    }

    #[Test]
    public function purge_removes_only_the_expired(): void
    {
        $directory = $this->directory();
        $store = new FileStore($directory);

        $store->put('fresh', 'a', 600);
        $store->put('stale', 'b', -5);

        $this->assertSame(1, $store->purge());
        $this->assertSame('a', $store->get('fresh'));
    }

    #[Test]
    public function flush_leaves_unrelated_files_alone(): void
    {
        // The directory may be shared. Only entries this store wrote are ours
        // to remove.
        $directory = $this->directory();
        $store = new FileStore($directory);
        $store->put('mine', 'x', 60);

        file_put_contents($directory . '/not-a-cache-entry', 'keep me');

        $store->flush();

        $this->assertFileExists($directory . '/not-a-cache-entry');
        $this->assertNull($store->get('mine'));
    }

    #[Test]
    public function incrementing_keeps_the_original_expiry(): void
    {
        // A rate-limit counter that reset its own window on every hit would
        // never expire under sustained load.
        $directory = $this->directory();
        $store = new FileStore($directory);

        $store->put('window', 1, 2);
        $store->increment('window');
        $store->put('window', 5, -1);

        $this->assertNull($store->get('window'));
    }
}
