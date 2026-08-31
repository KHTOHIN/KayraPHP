<?php

declare(strict_types=1);

namespace Kayra\Tests\Unit;

use Kayra\Foundation\PackageManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PackageManifest::class)]
final class PackageManifestTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/kayra_pkg_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/vendor/composer', 0o777, true);
        mkdir($this->tmp . '/cache', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/cache', '/vendor/composer', '/vendor'] as $sub) {
            foreach (glob($this->tmp . $sub . '/*') ?: [] as $f) {
                is_file($f) && @unlink($f);
            }
        }

        @rmdir($this->tmp . '/cache');
        @rmdir($this->tmp . '/vendor/composer');
        @rmdir($this->tmp . '/vendor');
        @rmdir($this->tmp);
    }

    /**
     * @param list<array<string, mixed>> $packages
     */
    private function installed(array $packages): void
    {
        file_put_contents(
            $this->tmp . '/vendor/composer/installed.json',
            json_encode(['packages' => $packages], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param list<string> $dontDiscover
     */
    private function manifest(array $dontDiscover = []): PackageManifest
    {
        return new PackageManifest(
            $this->tmp . '/vendor',
            $this->tmp . '/cache/packages.php',
            $dontDiscover,
        );
    }

    #[Test]
    public function it_discovers_providers_declared_by_packages(): void
    {
        $this->installed([
            ['name' => 'acme/widgets', 'extra' => ['kayra' => ['providers' => ['Acme\\WidgetServiceProvider']]]],
            ['name' => 'other/thing', 'extra' => []],
        ]);

        $this->assertSame(['Acme\\WidgetServiceProvider'], $this->manifest()->providers());
    }

    #[Test]
    public function packages_without_kayra_metadata_are_ignored(): void
    {
        $this->installed([
            ['name' => 'symfony/console'],
            ['name' => 'psr/log', 'extra' => ['branch-alias' => []]],
        ]);

        $this->assertSame([], $this->manifest()->providers());
    }

    #[Test]
    public function discovery_can_be_declined_per_package(): void
    {
        // An application may want to register the provider itself, or not at all.
        $this->installed([
            ['name' => 'acme/widgets', 'extra' => ['kayra' => ['providers' => ['Acme\\WidgetServiceProvider']]]],
        ]);

        $this->assertSame([], $this->manifest(['acme/widgets'])->providers());
    }

    #[Test]
    public function aliases_are_collected(): void
    {
        $this->installed([
            ['name' => 'acme/widgets', 'extra' => ['kayra' => ['aliases' => ['widget' => 'Acme\\Widget']]]],
        ]);

        $this->assertSame(['widget' => 'Acme\\Widget'], $this->manifest()->aliases());
    }

    #[Test]
    public function the_manifest_is_cached_and_the_cache_is_used(): void
    {
        $this->installed([
            ['name' => 'acme/widgets', 'extra' => ['kayra' => ['providers' => ['Acme\\First']]]],
        ]);

        $manifest = $this->manifest();
        $manifest->rebuild();

        $this->assertFileExists($this->tmp . '/cache/packages.php');

        // Change what is installed; a fresh instance must read the cache, not disk.
        $this->installed([
            ['name' => 'acme/widgets', 'extra' => ['kayra' => ['providers' => ['Acme\\Second']]]],
        ]);

        $this->assertSame(['Acme\\First'], $this->manifest()->providers());
    }

    #[Test]
    public function rebuilding_picks_up_changes(): void
    {
        $this->installed([['name' => 'a/b', 'extra' => ['kayra' => ['providers' => ['A\\One']]]]]);
        $this->manifest()->rebuild();

        $this->installed([['name' => 'a/b', 'extra' => ['kayra' => ['providers' => ['A\\Two']]]]]);

        $this->assertSame(['A\\Two'], $this->manifest()->rebuild()['a/b']['providers']);
    }

    #[Test]
    public function a_missing_installed_json_is_not_an_error(): void
    {
        // A project checked out without `composer install` must still boot far
        // enough to report that.
        $this->assertSame([], $this->manifest()->providers());
    }

    #[Test]
    public function malformed_metadata_is_skipped_rather_than_fatal(): void
    {
        $this->installed([
            ['name' => 'bad/one', 'extra' => ['kayra' => ['providers' => 'not-a-list']]],
            ['name' => 'bad/two', 'extra' => ['kayra' => ['providers' => [123, null]]]],
            ['name' => 'good/one', 'extra' => ['kayra' => ['providers' => ['Good\\Provider']]]],
        ]);

        // A bare string is not coerced into a one-element list: that would turn
        // a package's typo into a provider name failing far from its cause.
        $this->assertSame(['Good\\Provider'], $this->manifest()->providers());
    }
}
