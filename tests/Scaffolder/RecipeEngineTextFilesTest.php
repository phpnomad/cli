<?php

namespace PHPNomad\Cli\Tests\Scaffolder;

use PHPNomad\Cli\Indexer\ProjectIndexer;
use PHPNomad\Cli\Scaffolder\InitializerMutator;
use PHPNomad\Cli\Scaffolder\KitDiscoverer;
use PHPNomad\Cli\Scaffolder\NamespaceResolver;
use PHPNomad\Cli\Scaffolder\PreflightValidator;
use PHPNomad\Cli\Scaffolder\RecipeEngine;
use PHPNomad\Cli\Scaffolder\RecipeLoader;
use PHPNomad\Cli\Scaffolder\TemplateRenderer;
use PHPNomad\Cli\Scaffolder\VarResolver;
use PHPNomad\Cli\Tests\Support\FakeOutput;
use PHPUnit\Framework\TestCase;

/**
 * Non-PHP (plain text) recipe outputs must generate without any composer.json
 * PSR-4 mapping in the target project. This is what lets a kit scaffold
 * TypeScript workers, Dockerfiles, YAML configs, etc.
 */
class RecipeEngineTextFilesTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/phpnomad-textfiles-' . uniqid();
        mkdir($this->projectDir . '/.phpnomad/recipes', 0755, true);
        mkdir($this->projectDir . '/.phpnomad/templates', 0755, true);

        // A JS-style project: package.json, no composer.json.
        file_put_contents($this->projectDir . '/package.json', '{"name":"probe"}');

        file_put_contents(
            $this->projectDir . '/.phpnomad/recipes/text-probe.json',
            json_encode([
                'name' => 'text-probe',
                'kind' => 'scaffolding',
                'summary' => 'probe',
                'description' => 'probe',
                'vars' => [
                    'workerName' => [
                        'type' => 'string',
                        'description' => 'Worker name',
                        'example' => 'demo',
                    ],
                ],
                'files' => [
                    ['path' => 'out/{{workerName}}/src/index.ts', 'template' => 'text-probe-index'],
                    ['path' => 'out/{{workerName}}/Dockerfile', 'template' => 'text-probe-dockerfile'],
                ],
            ])
        );

        file_put_contents(
            $this->projectDir . '/.phpnomad/templates/text-probe-index.php.tpl',
            "console.log('{{workerName}}');\n"
        );
        file_put_contents(
            $this->projectDir . '/.phpnomad/templates/text-probe-dockerfile.php.tpl',
            "FROM node:22-slim\n# {{workerName}}\n"
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectDir);
    }

    public function testTextOnlyRecipeGeneratesWithoutComposerJson(): void
    {
        $engine = $this->createEngine();
        $output = new FakeOutput();

        $code = $engine->execute(
            'text-probe',
            ['workerName' => 'demo'],
            $this->projectDir,
            $this->createIndexer(),
            $output
        );

        $this->assertSame(0, $code, $output->text());
        $this->assertFileExists($this->projectDir . '/out/demo/src/index.ts');
        $this->assertFileExists($this->projectDir . '/out/demo/Dockerfile');
        $this->assertSame(
            "console.log('demo');\n",
            file_get_contents($this->projectDir . '/out/demo/src/index.ts')
        );
        $this->assertSame(
            "FROM node:22-slim\n# demo\n",
            file_get_contents($this->projectDir . '/out/demo/Dockerfile')
        );
    }

    private function createEngine(): RecipeEngine
    {
        return new RecipeEngine(
            new RecipeLoader(new KitDiscoverer()),
            new TemplateRenderer(),
            new NamespaceResolver(),
            new VarResolver(),
            new InitializerMutator(),
            new PreflightValidator(new NamespaceResolver())
        );
    }

    private function createIndexer(): ProjectIndexer
    {
        return new class extends ProjectIndexer {
            public function __construct()
            {
            }
        };
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
