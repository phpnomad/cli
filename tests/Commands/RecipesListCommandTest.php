<?php

namespace PHPNomad\Cli\Tests\Commands;

use PHPNomad\Cli\Commands\RecipesListCommand;
use PHPNomad\Cli\Scaffolder\KitDiscoverer;
use PHPNomad\Cli\Scaffolder\ProjectConfig;
use PHPNomad\Cli\Scaffolder\RecipeLoader;
use PHPNomad\Cli\Scaffolder\RecipeRegistry;
use PHPNomad\Cli\Tests\Support\FakeInput;
use PHPNomad\Cli\Tests\Support\FakeOutput;
use PHPUnit\Framework\TestCase;

class RecipesListCommandTest extends TestCase
{
    private string $projectPath;

    protected function setUp(): void
    {
        $this->projectPath = sys_get_temp_dir() . '/phpnomad-list-test-' . uniqid();
        mkdir($this->projectPath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectPath);
    }

    public function testJsonOutputContainsRecipesArray(): void
    {
        $this->installKit('phpnomad', 'core-recipes', [
            'datastore.json' => [
                'name' => 'datastore',
                'summary' => 'Typed CRUD access layer',
                'tags' => ['data', 'core'],
                'examples' => ['I need to track customer orders'],
            ],
        ]);

        $output = new FakeOutput();
        $command = $this->makeCommand($output);

        $exit = $command->handle(new FakeInput(['path' => $this->projectPath, 'format' => 'json']));

        $this->assertSame(0, $exit);

        $payload = json_decode($output->text(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('recipes', $payload);
        $this->assertCount(1, $payload['recipes']);

        $entry = $payload['recipes'][0];
        $this->assertSame('phpnomad/datastore', $entry['name']);
        $this->assertSame('Typed CRUD access layer', $entry['summary']);
        $this->assertSame(['data', 'core'], $entry['tags']);
        $this->assertSame(['I need to track customer orders'], $entry['examples']);
        $this->assertSame('phpnomad', $entry['originKit']['vendor']);
        $this->assertSame('core-recipes', $entry['originKit']['packageName']);
    }

    public function testActiveFilterAppliedByDefault(): void
    {
        $this->installKit('phpnomad', 'core-recipes', [
            'datastore.json' => ['name' => 'datastore'],
            'listener.json' => ['name' => 'listener'],
        ]);

        @mkdir($this->projectPath . '/.phpnomad', 0755, true);
        file_put_contents($this->projectPath . '/.phpnomad/config.json', json_encode([
            'recipes' => ['active' => ['phpnomad/datastore']],
        ]));

        $output = new FakeOutput();
        $command = $this->makeCommand($output);

        $command->handle(new FakeInput(['path' => $this->projectPath, 'format' => 'json']));

        $payload = json_decode($output->text(), true);
        $this->assertCount(1, $payload['recipes']);
        $this->assertSame('phpnomad/datastore', $payload['recipes'][0]['name']);
    }

    public function testAllFlagBypassesActiveFilter(): void
    {
        $this->installKit('phpnomad', 'core-recipes', [
            'datastore.json' => ['name' => 'datastore'],
            'listener.json' => ['name' => 'listener'],
        ]);

        @mkdir($this->projectPath . '/.phpnomad', 0755, true);
        file_put_contents($this->projectPath . '/.phpnomad/config.json', json_encode([
            'recipes' => ['active' => ['phpnomad/datastore']],
        ]));

        $output = new FakeOutput();
        $command = $this->makeCommand($output);

        $command->handle(new FakeInput(['path' => $this->projectPath, 'format' => 'json', 'all' => true]));

        $payload = json_decode($output->text(), true);
        $this->assertCount(2, $payload['recipes']);
    }

    public function testProjectLocalRecipesIncluded(): void
    {
        $this->installKit('phpnomad', 'core-recipes', [
            'datastore.json' => ['name' => 'datastore'],
        ]);

        $localDir = $this->projectPath . '/.phpnomad/recipes';
        mkdir($localDir, 0755, true);
        file_put_contents($localDir . '/custom.json', json_encode([
            'name' => 'custom',
            'summary' => 'Project-specific scaffolding',
        ]));

        $output = new FakeOutput();
        $command = $this->makeCommand($output);

        $command->handle(new FakeInput(['path' => $this->projectPath, 'format' => 'json']));

        $payload = json_decode($output->text(), true);

        $names = array_column($payload['recipes'], 'name');
        $this->assertContains('phpnomad/datastore', $names);
        $this->assertContains('custom', $names);

        $custom = $payload['recipes'][array_search('custom', $names, true)];
        $this->assertNull($custom['originKit']);
    }

    public function testEmptyResultProducesEmptyArray(): void
    {
        $output = new FakeOutput();
        $command = $this->makeCommand($output);

        $command->handle(new FakeInput(['path' => $this->projectPath, 'format' => 'json']));

        $payload = json_decode($output->text(), true);
        $this->assertSame([], $payload['recipes']);
    }

    public function testTableFormatGroupsByKit(): void
    {
        $this->installKit('phpnomad', 'core-recipes', [
            'datastore.json' => ['name' => 'datastore', 'summary' => 'Data layer'],
        ]);

        $output = new FakeOutput();
        $command = $this->makeCommand($output);

        $command->handle(new FakeInput(['path' => $this->projectPath, 'format' => 'table']));

        $combined = $output->text();
        $this->assertStringContainsString('phpnomad/core-recipes', $combined);
        $this->assertStringContainsString('phpnomad/datastore', $combined);
        $this->assertStringContainsString('Data layer', $combined);
    }

    public function testInvalidPathReturnsError(): void
    {
        $output = new FakeOutput();
        $command = $this->makeCommand($output);

        $exit = $command->handle(new FakeInput(['path' => '/nonexistent/path/here', 'format' => 'json']));

        $this->assertSame(1, $exit);
    }

    private function makeCommand(FakeOutput $output): RecipesListCommand
    {
        $discoverer = new KitDiscoverer();
        $loader = new RecipeLoader($discoverer);
        $config = new ProjectConfig();
        $registry = new RecipeRegistry($discoverer, $loader, $config);

        return new RecipesListCommand($output, $registry);
    }

    /**
     * @param array<string, array<string, mixed>> $recipes
     */
    private function installKit(string $vendor, string $package, array $recipes): void
    {
        $packageDir = $this->projectPath . '/vendor/' . $vendor . '/' . $package;
        mkdir($packageDir . '/recipes', 0755, true);
        mkdir($packageDir . '/templates', 0755, true);

        file_put_contents($packageDir . '/composer.json', json_encode([
            'name' => $vendor . '/' . $package,
            'extra' => ['phpnomad' => ['recipes' => 'recipes/', 'templates' => 'templates/']],
        ]));

        foreach ($recipes as $filename => $recipe) {
            file_put_contents($packageDir . '/recipes/' . $filename, json_encode($recipe));
        }
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
