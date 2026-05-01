<?php

namespace PHPNomad\Cli\Tests\Scaffolder;

use PHPNomad\Cli\Scaffolder\KitDiscoverer;
use PHPUnit\Framework\TestCase;

class KitDiscovererTest extends TestCase
{
    private string $projectPath;
    private KitDiscoverer $discoverer;

    protected function setUp(): void
    {
        $this->projectPath = sys_get_temp_dir() . '/phpnomad-kit-test-' . uniqid();
        mkdir($this->projectPath, 0755, true);
        $this->discoverer = new KitDiscoverer();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectPath);
    }

    public function testDiscoversKitFromVendor(): void
    {
        $this->writeKit('phpnomad', 'core-recipes', [
            'extra' => ['phpnomad' => ['recipes' => 'recipes/', 'templates' => 'templates/']],
        ]);

        $kits = $this->discoverer->discover($this->projectPath);

        $this->assertCount(1, $kits);
        $this->assertArrayHasKey('phpnomad/core-recipes', $kits);

        $kit = $kits['phpnomad/core-recipes'];
        $this->assertSame('phpnomad', $kit->vendor);
        $this->assertSame('core-recipes', $kit->packageName);
        $this->assertStringEndsWith('phpnomad/core-recipes/recipes', $kit->recipesDir);
        $this->assertStringEndsWith('phpnomad/core-recipes/templates', $kit->templatesDir);
    }

    public function testIgnoresPackagesWithoutPhpnomadExtra(): void
    {
        $this->writeKit('vendor1', 'pkg-a', [
            'extra' => ['phpnomad' => ['recipes' => 'recipes/', 'templates' => 'templates/']],
        ]);
        $this->writeKit('vendor2', 'pkg-b', []);

        $kits = $this->discoverer->discover($this->projectPath);

        $this->assertCount(1, $kits);
        $this->assertArrayHasKey('vendor1/pkg-a', $kits);
    }

    public function testIgnoresKitsMissingDeclaredDirectories(): void
    {
        $vendorDir = $this->projectPath . '/vendor/phpnomad/broken-recipes';
        mkdir($vendorDir, 0755, true);

        file_put_contents($vendorDir . '/composer.json', json_encode([
            'name' => 'phpnomad/broken-recipes',
            'extra' => ['phpnomad' => ['recipes' => 'missing/', 'templates' => 'also-missing/']],
        ]));

        $kits = $this->discoverer->discover($this->projectPath);

        $this->assertSame([], $kits);
    }

    public function testReturnsEmptyWhenNoVendorDir(): void
    {
        $kits = $this->discoverer->discover($this->projectPath);

        $this->assertSame([], $kits);
    }

    public function testFindByFullName(): void
    {
        $this->writeKit('phpnomad', 'core-recipes', [
            'extra' => ['phpnomad' => ['recipes' => 'recipes/', 'templates' => 'templates/']],
        ]);

        $kit = $this->discoverer->findByFullName($this->projectPath, 'phpnomad/core-recipes');
        $this->assertNotNull($kit);
        $this->assertSame('phpnomad', $kit->vendor);

        $missing = $this->discoverer->findByFullName($this->projectPath, 'nope/missing');
        $this->assertNull($missing);
    }

    public function testFindByVendorReturnsFirstMatch(): void
    {
        $this->writeKit('phpnomad', 'core-recipes', [
            'extra' => ['phpnomad' => ['recipes' => 'recipes/', 'templates' => 'templates/']],
        ]);

        $kit = $this->discoverer->findByVendor($this->projectPath, 'phpnomad');
        $this->assertNotNull($kit);
        $this->assertSame('core-recipes', $kit->packageName);

        $missing = $this->discoverer->findByVendor($this->projectPath, 'unknown');
        $this->assertNull($missing);
    }

    public function testCacheReusesResults(): void
    {
        $this->writeKit('phpnomad', 'core-recipes', [
            'extra' => ['phpnomad' => ['recipes' => 'recipes/', 'templates' => 'templates/']],
        ]);

        $first = $this->discoverer->discover($this->projectPath);

        // Add a second kit after the first call. Without cache invalidation the result should be unchanged.
        $this->writeKit('phpnomad', 'wordpress-recipes', [
            'extra' => ['phpnomad' => ['recipes' => 'recipes/', 'templates' => 'templates/']],
        ]);

        $second = $this->discoverer->discover($this->projectPath);
        $this->assertCount(1, $second);

        $this->discoverer->clearCache();
        $third = $this->discoverer->discover($this->projectPath);
        $this->assertCount(2, $third);
    }

    public function testIgnoresMalformedComposerJson(): void
    {
        $vendorDir = $this->projectPath . '/vendor/phpnomad/broken';
        mkdir($vendorDir, 0755, true);
        file_put_contents($vendorDir . '/composer.json', '{not valid json');

        $this->writeKit('phpnomad', 'core-recipes', [
            'extra' => ['phpnomad' => ['recipes' => 'recipes/', 'templates' => 'templates/']],
        ]);

        $kits = $this->discoverer->discover($this->projectPath);

        $this->assertCount(1, $kits);
        $this->assertArrayHasKey('phpnomad/core-recipes', $kits);
    }

    /**
     * @param array<string, mixed> $extraComposerData
     */
    private function writeKit(string $vendor, string $package, array $extraComposerData): void
    {
        $packageDir = $this->projectPath . '/vendor/' . $vendor . '/' . $package;
        mkdir($packageDir . '/recipes', 0755, true);
        mkdir($packageDir . '/templates', 0755, true);

        $composer = array_merge(['name' => $vendor . '/' . $package], $extraComposerData);

        file_put_contents($packageDir . '/composer.json', json_encode($composer));
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
