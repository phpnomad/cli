<?php

namespace PHPNomad\Cli\Tests\Scaffolder;

use PHPNomad\Cli\Scaffolder\ProjectConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProjectConfigTest extends TestCase
{
    private string $projectPath;
    private ProjectConfig $config;

    protected function setUp(): void
    {
        $this->projectPath = sys_get_temp_dir() . '/phpnomad-config-test-' . uniqid();
        mkdir($this->projectPath, 0755, true);
        $this->config = new ProjectConfig();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectPath);
    }

    public function testMissingConfigReturnsNullActive(): void
    {
        $result = $this->config->load($this->projectPath);

        $this->assertNull($result['active']);
        $this->assertNull($result['path']);
    }

    public function testActiveListReturnedWhenPresent(): void
    {
        $this->writeConfig(['recipes' => ['active' => ['phpnomad/datastore', 'phpnomad/listener']]]);

        $active = $this->config->activeRecipes($this->projectPath);

        $this->assertSame(['phpnomad/datastore', 'phpnomad/listener'], $active);
    }

    public function testIsActiveTrueWhenInList(): void
    {
        $this->writeConfig(['recipes' => ['active' => ['phpnomad/datastore']]]);

        $this->assertTrue($this->config->isActive($this->projectPath, 'phpnomad/datastore'));
        $this->assertFalse($this->config->isActive($this->projectPath, 'phpnomad/listener'));
    }

    public function testIsActiveTrueForAllWhenNoConfig(): void
    {
        $this->assertTrue($this->config->isActive($this->projectPath, 'anything/at-all'));
    }

    public function testEmptyActiveListMeansNothingActive(): void
    {
        $this->writeConfig(['recipes' => ['active' => []]]);

        $this->assertFalse($this->config->isActive($this->projectPath, 'phpnomad/datastore'));
    }

    public function testInvalidJsonThrows(): void
    {
        $configPath = $this->projectPath . '/.phpnomad/config.json';
        mkdir(dirname($configPath), 0755, true);
        file_put_contents($configPath, '{not valid');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $this->config->load($this->projectPath);
    }

    public function testInvalidActiveTypeThrows(): void
    {
        $this->writeConfig(['recipes' => ['active' => 'not-an-array']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be an array');

        $this->config->load($this->projectPath);
    }

    public function testConfigFoundFromSubdirectory(): void
    {
        $this->writeConfig(['recipes' => ['active' => ['phpnomad/datastore']]]);

        $sub = $this->projectPath . '/lib/deep/nested';
        mkdir($sub, 0755, true);

        $active = $this->config->activeRecipes($sub);

        $this->assertSame(['phpnomad/datastore'], $active);
    }

    public function testCacheReusesResult(): void
    {
        $this->writeConfig(['recipes' => ['active' => ['phpnomad/datastore']]]);

        $first = $this->config->activeRecipes($this->projectPath);
        $this->assertSame(['phpnomad/datastore'], $first);

        $this->writeConfig(['recipes' => ['active' => ['phpnomad/listener']]]);

        $second = $this->config->activeRecipes($this->projectPath);
        $this->assertSame(['phpnomad/datastore'], $second, 'Cache should hold the original value');

        $this->config->clearCache();

        $third = $this->config->activeRecipes($this->projectPath);
        $this->assertSame(['phpnomad/listener'], $third);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeConfig(array $data): void
    {
        $configPath = $this->projectPath . '/.phpnomad/config.json';
        @mkdir(dirname($configPath), 0755, true);
        file_put_contents($configPath, json_encode($data));
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
