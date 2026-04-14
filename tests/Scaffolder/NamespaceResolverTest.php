<?php

namespace PHPNomad\Cli\Tests\Scaffolder;

use PHPNomad\Cli\Scaffolder\NamespaceResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NamespaceResolverTest extends TestCase
{
    private NamespaceResolver $resolver;
    private string $projectPath;

    protected function setUp(): void
    {
        $this->resolver = new NamespaceResolver();
        // Use this project itself as the test project
        $this->projectPath = dirname(__DIR__, 2);
    }

    public function testResolveDirectSubdirectory(): void
    {
        $namespace = $this->resolver->resolve('lib/Commands/TestCommand.php', $this->projectPath);

        $this->assertSame('PHPNomad\\Cli\\Commands', $namespace);
    }

    public function testResolveNestedSubdirectory(): void
    {
        $namespace = $this->resolver->resolve('lib/Indexer/Models/GraphNode.php', $this->projectPath);

        $this->assertSame('PHPNomad\\Cli\\Indexer\\Models', $namespace);
    }

    public function testResolveRootNamespace(): void
    {
        $namespace = $this->resolver->resolve('lib/Application.php', $this->projectPath);

        $this->assertSame('PHPNomad\\Cli', $namespace);
    }

    public function testResolveFilePath(): void
    {
        $path = $this->resolver->resolveFilePath('PHPNomad\\Cli\\Initializer', $this->projectPath);

        $this->assertNotNull($path);
        $this->assertStringEndsWith('lib/Initializer.php', $path);
    }

    public function testResolveFilePathReturnsNullForUnknownNamespace(): void
    {
        $path = $this->resolver->resolveFilePath('Some\\Unknown\\Namespace\\Class', $this->projectPath);

        $this->assertNull($path);
    }

    public function testNoComposerJsonThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer.json not found');

        $this->resolver->resolve('lib/Test.php', '/nonexistent/path');
    }

    public function testCustomPsr4Mapping(): void
    {
        $tmpDir = sys_get_temp_dir() . '/phpnomad-test-' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => ['App\\' => 'src/'],
            ],
        ]));

        try {
            $namespace = $this->resolver->resolve('src/Services/PayoutService.php', $tmpDir);

            $this->assertSame('App\\Services', $namespace);
        } finally {
            unlink($tmpDir . '/composer.json');
            rmdir($tmpDir);
        }
    }

    public function testMultiplePsr4MappingsPicksMostSpecific(): void
    {
        $tmpDir = sys_get_temp_dir() . '/phpnomad-test-' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                    'App\\Tests\\' => 'tests/',
                ],
            ],
        ]));

        try {
            $namespace = $this->resolver->resolve('tests/Unit/SomeTest.php', $tmpDir);

            $this->assertSame('App\\Tests\\Unit', $namespace);
        } finally {
            unlink($tmpDir . '/composer.json');
            rmdir($tmpDir);
        }
    }
}
