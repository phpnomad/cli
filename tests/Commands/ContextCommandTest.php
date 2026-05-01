<?php

namespace PHPNomad\Cli\Tests\Commands;

use PHPNomad\Cli\Commands\ContextCommand;
use PHPNomad\Cli\Indexer\ContextRenderer;
use PHPNomad\Cli\Indexer\Models\ProjectIndex;
use PHPNomad\Cli\Indexer\ProjectIndexer;
use PHPNomad\Cli\Tests\Support\FakeInput;
use PHPNomad\Cli\Tests\Support\FakeOutput;
use PHPUnit\Framework\TestCase;

class ContextCommandTest extends TestCase
{
    public function testFreshIndexProgressDoesNotPolluteStdoutContext(): void
    {
        $output = new FakeOutput();
        $indexer = new class extends ProjectIndexer {
            public function __construct()
            {
            }

            public function load(string $path): ?ProjectIndex
            {
                return null;
            }

            public function index(string $path, \PHPNomad\Console\Interfaces\OutputStrategy $output): ProjectIndex
            {
                $output->info('Scanning project files...');

                return new ProjectIndex(
                    $path,
                    '2026-05-01T00:00:00+00:00',
                    [],
                    [],
                    []
                );
            }
        };

        $command = new ContextCommand($output, $indexer, new ContextRenderer());
        $code = $command->handle(new FakeInput([
            'path' => __DIR__,
            'fresh' => true,
            'output' => 'stdout',
        ]));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('# Project Context', $output->text());
        $this->assertStringNotContainsString('Scanning project files', $output->text());
    }
}
