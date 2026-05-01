<?php

namespace PHPNomad\Cli\Tests\Commands;

use PHPNomad\Cli\Commands\IndexCommand;
use PHPNomad\Cli\Indexer\Models\IndexedApplication;
use PHPNomad\Cli\Indexer\Models\ProjectIndex;
use PHPNomad\Cli\Indexer\ProjectIndexer;
use PHPNomad\Cli\Scaffolder\KitDiscoverer;
use PHPNomad\Cli\Scaffolder\ProjectConfig;
use PHPNomad\Cli\Scaffolder\RecipeLoader;
use PHPNomad\Cli\Scaffolder\RecipeRegistry;
use PHPNomad\Cli\Tests\Support\FakeInput;
use PHPNomad\Cli\Tests\Support\FakeOutput;
use PHPUnit\Framework\TestCase;

class IndexCommandTest extends TestCase
{
    public function testSummaryFormatEmitsStableAgentLines(): void
    {
        $output = new FakeOutput();
        $indexer = new class extends ProjectIndexer {
            public function __construct()
            {
            }

            public function index(string $path, \PHPNomad\Console\Interfaces\OutputStrategy $output): ProjectIndex
            {
                $output->info('Scanning project files...');
                $output->writeln('  Found 1 class');

                return new ProjectIndex(
                    $path,
                    '2026-05-01T00:00:00+00:00',
                    ['A' => (object) []],
                    [new IndexedApplication('App\\Application', 'Application.php', [], [], [])],
                    [],
                    [],
                    ['Command' => (object) []]
                );
            }

            public function save(ProjectIndex $index, string $path): string
            {
                return rtrim($path, '/') . '/.phpnomad';
            }
        };

        $discoverer = new KitDiscoverer();
        $registry = new RecipeRegistry($discoverer, new RecipeLoader($discoverer), new ProjectConfig());

        $command = new IndexCommand($output, $indexer, $registry);
        $code = $command->handle(new FakeInput([
            'path' => __DIR__,
            'format' => 'summary',
        ]));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('index: written=', $output->text());
        $this->assertStringContainsString('summary:', $output->text());
        $this->assertStringContainsString('applications=1', $output->text());
        $this->assertStringContainsString('commands=1', $output->text());
        $this->assertStringNotContainsString('Scanning project files', $output->text());
        $this->assertStringNotContainsString('meta.json, classes.jsonl', $output->text());
    }
}
