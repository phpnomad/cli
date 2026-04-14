<?php

namespace PHPNomad\Cli\Tests\Commands;

use PHPNomad\Cli\Commands\MakeCommand;
use PHPNomad\Cli\Indexer\ProjectIndexer;
use PHPNomad\Cli\Scaffolder\RecipeEngine;
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
        $subDir = $projectRoot . '/lib/Scaffolder/Recipes';
        $result = $this->callFindProjectRoot($command, $subDir);

        $this->assertSame($projectRoot, $result);
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
