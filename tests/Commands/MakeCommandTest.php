<?php

namespace PHPNomad\Cli\Tests\Commands;

use PHPNomad\Cli\Commands\MakeCommand;
use PHPNomad\Cli\Indexer\ProjectIndexer;
use PHPNomad\Cli\Scaffolder\RecipeEngine;
use PHPNomad\Cli\Tests\Support\FakeInput;
use PHPNomad\Cli\Tests\Support\FakeOutput;
use PHPNomad\Console\Interfaces\OutputStrategy;
use PHPUnit\Framework\TestCase;

class MakeCommandTest extends TestCase
{
    public function testFindProjectRootAtCurrentDir(): void
    {
        $command = $this->createCommand();

        // The CLI project root itself has composer.json
        $projectRoot = dirname(__DIR__, 2);
        $result = $this->callFindProjectRoot($command, $projectRoot);

        $this->assertSame($projectRoot, $result);
    }

    public function testFindProjectRootFromSubdirectory(): void
    {
        $command = $this->createCommand();

        $projectRoot = dirname(__DIR__, 2);
        $subDir = $projectRoot . '/lib/Scaffolder';
        $result = $this->callFindProjectRoot($command, $subDir);

        $this->assertSame($projectRoot, $result);
    }

    public function testFindProjectRootFromDeepSubdirectory(): void
    {
        $command = $this->createCommand();

        $projectRoot = dirname(__DIR__, 2);
        $subDir = $projectRoot . '/lib/Scaffolder/Models';
        $result = $this->callFindProjectRoot($command, $subDir);

        $this->assertSame($projectRoot, $result);
    }

    public function testFindProjectRootAcceptsPackageJsonProjects(): void
    {
        $command = $this->createCommand();

        $tmpDir = sys_get_temp_dir() . '/phpnomad-test-' . uniqid();
        mkdir($tmpDir . '/sub', 0755, true);
        file_put_contents($tmpDir . '/package.json', '{"name":"probe"}');

        try {
            $this->assertSame($tmpDir, $this->callFindProjectRoot($command, $tmpDir));
            $this->assertSame($tmpDir, $this->callFindProjectRoot($command, $tmpDir . '/sub'));
        } finally {
            unlink($tmpDir . '/package.json');
            rmdir($tmpDir . '/sub');
            rmdir($tmpDir);
        }
    }

    public function testFindProjectRootAcceptsPhpnomadDirProjects(): void
    {
        $command = $this->createCommand();

        $tmpDir = sys_get_temp_dir() . '/phpnomad-test-' . uniqid();
        mkdir($tmpDir . '/.phpnomad', 0755, true);

        try {
            $this->assertSame($tmpDir, $this->callFindProjectRoot($command, $tmpDir));
        } finally {
            rmdir($tmpDir . '/.phpnomad');
            rmdir($tmpDir);
        }
    }

    public function testFindProjectRootReturnsNullWhenNotFound(): void
    {
        $command = $this->createCommand();

        // /tmp typically has no composer.json anywhere in its ancestry
        $tmpDir = sys_get_temp_dir() . '/phpnomad-test-' . uniqid();
        mkdir($tmpDir);

        try {
            $result = $this->callFindProjectRoot($command, $tmpDir);

            $this->assertNull($result);
        } finally {
            rmdir($tmpDir);
        }
    }

    public function testInvalidJsonVarsFailBeforeRecipeExecution(): void
    {
        $output = new FakeOutput();
        $engine = new class extends RecipeEngine {
            public bool $executed = false;

            public function __construct()
            {
            }

            public function execute(string $from, array $vars, string $projectPath, ProjectIndexer $indexer, OutputStrategy $output): int
            {
                $this->executed = true;
                return 0;
            }
        };

        $indexer = new class extends ProjectIndexer {
            public function __construct()
            {
            }
        };

        $command = new MakeCommand($output, $engine, $indexer);
        $code = $command->handle(new FakeInput([
            'from' => 'listener',
            'path' => dirname(__DIR__, 2),
            'vars' => '{invalid',
        ]));

        $this->assertSame(1, $code);
        $this->assertFalse($engine->executed);
        $this->assertStringContainsString('Invalid vars JSON', $output->text());
    }

    public function testJsonArrayVarsFailBecauseMakeExpectsObject(): void
    {
        $output = new FakeOutput();
        $engine = new class extends RecipeEngine {
            public bool $executed = false;

            public function __construct()
            {
            }

            public function execute(string $from, array $vars, string $projectPath, ProjectIndexer $indexer, OutputStrategy $output): int
            {
                $this->executed = true;
                return 0;
            }
        };

        $indexer = new class extends ProjectIndexer {
            public function __construct()
            {
            }
        };

        $command = new MakeCommand($output, $engine, $indexer);
        $code = $command->handle(new FakeInput([
            'from' => 'listener',
            'path' => dirname(__DIR__, 2),
            'vars' => '["not", "an", "object"]',
        ]));

        $this->assertSame(1, $code);
        $this->assertFalse($engine->executed);
        $this->assertStringContainsString('expected an object', $output->text());
    }

    private function createCommand(): MakeCommand
    {
        return new MakeCommand(
            $this->createMock(OutputStrategy::class),
            $this->createMock(RecipeEngine::class),
            $this->createMock(ProjectIndexer::class)
        );
    }

    private function callFindProjectRoot(MakeCommand $command, string $path): ?string
    {
        $method = new \ReflectionMethod($command, 'findProjectRoot');

        return $method->invoke($command, $path);
    }
}
