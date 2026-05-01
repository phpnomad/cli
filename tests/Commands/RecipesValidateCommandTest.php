<?php

namespace PHPNomad\Cli\Tests\Commands;

use PHPNomad\Cli\Commands\RecipesValidateCommand;
use PHPNomad\Cli\Scaffolder\KitDiscoverer;
use PHPNomad\Cli\Scaffolder\ProjectConfig;
use PHPNomad\Cli\Scaffolder\RecipeLoader;
use PHPNomad\Cli\Scaffolder\RecipeRegistry;
use PHPNomad\Cli\Scaffolder\RecipeValidator;
use PHPNomad\Cli\Tests\Support\FakeOutput;
use PHPNomad\Cli\Tests\Support\FakeInput;
use PHPUnit\Framework\TestCase;

class RecipesValidateCommandTest extends TestCase
{
    private string $projectPath;

    protected function setUp(): void
    {
        $this->projectPath = sys_get_temp_dir() . '/phpnomad-validate-test-' . uniqid();
        mkdir($this->projectPath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectPath);
    }

    public function testValidKitExitsZero(): void
    {
        $this->installKit('phpnomad', 'core-recipes', [
            'datastore.json' => ['name' => 'datastore', 'summary' => 'A datastore'],
            'listener.json' => ['name' => 'listener'],
        ]);

        $output = new FakeOutput();
        $command = $this->makeCommand($output);

        $exit = $command->handle(new FakeInput(['path' => $this->projectPath]));

        $this->assertSame(0, $exit, 'Valid kit should exit 0. Output was: ' . $output->text());
        $this->assertStringContainsString('All recipes valid', $output->text());
    }

    public function testInvalidKitExitsNonZero(): void
    {
        $this->installKit('phpnomad', 'broken-recipes', [
            'broken.json' => [
                'name' => 'broken',
                'kind' => 'invalid-kind',
                'tags' => ['valid', 99],
            ],
        ]);

        $output = new FakeOutput();
        $command = $this->makeCommand($output);

        $exit = $command->handle(new FakeInput(['path' => $this->projectPath]));

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('fail', $output->text());
        $this->assertStringContainsString('error(s)', $output->text());
    }

    public function testKitFilterRestrictsScope(): void
    {
        $this->installKit('phpnomad', 'good-recipes', [
            'good.json' => ['name' => 'good'],
        ]);
        $this->installKit('phpnomad', 'bad-recipes', [
            'bad.json' => ['name' => 'bad', 'kind' => 'wrong-kind'],
        ]);

        $output = new FakeOutput();
        $command = $this->makeCommand($output);

        $exit = $command->handle(new FakeInput([
            'path' => $this->projectPath,
            'kit' => 'phpnomad/good-recipes',
        ]));

        $this->assertSame(0, $exit);
    }

    public function testNonexistentKitFilterFails(): void
    {
        $output = new FakeOutput();
        $command = $this->makeCommand($output);

        $exit = $command->handle(new FakeInput([
            'path' => $this->projectPath,
            'kit' => 'nope/missing',
        ]));

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not installed', $output->text());
    }

    public function testProjectLocalRecipesValidated(): void
    {
        $localDir = $this->projectPath . '/.phpnomad/recipes';
        mkdir($localDir, 0755, true);

        file_put_contents($localDir . '/local-broken.json', json_encode([
            'name' => 'local-broken',
            'kind' => 'oops',
        ]));

        $output = new FakeOutput();
        $command = $this->makeCommand($output);

        $exit = $command->handle(new FakeInput(['path' => $this->projectPath]));

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Project-local', $output->text());
        $this->assertStringContainsString('local-broken', $output->text());
    }

    private function makeCommand(FakeOutput $output): RecipesValidateCommand
    {
        $discoverer = new KitDiscoverer();
        $loader = new RecipeLoader($discoverer);
        $config = new ProjectConfig();
        $registry = new RecipeRegistry($discoverer, $loader, $config);

        return new RecipesValidateCommand($output, $discoverer, $registry, new RecipeValidator());
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
