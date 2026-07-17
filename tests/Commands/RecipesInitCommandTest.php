<?php

namespace PHPNomad\Cli\Tests\Commands;

use PHPNomad\Cli\Commands\RecipesInitCommand;
use PHPNomad\Cli\Scaffolder\KitDiscoverer;
use PHPNomad\Cli\Scaffolder\RecipeLoader;
use PHPNomad\Cli\Scaffolder\TemplateRenderer;
use PHPNomad\Cli\Tests\Support\FakeInput;
use PHPNomad\Cli\Tests\Support\FakeOutput;
use PHPUnit\Framework\TestCase;

class RecipesInitCommandTest extends TestCase
{
    private string $projectPath;

    protected function setUp(): void
    {
        $this->projectPath = sys_get_temp_dir() . '/phpnomad-recipes-init-test-' . uniqid();
        mkdir($this->projectPath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectPath);
    }

    public function testCreatesRecipeAndTemplateStubs(): void
    {
        $output = new FakeOutput();
        $command = new RecipesInitCommand($output);

        $code = $command->handle(new FakeInput([
            'name' => 'payout-service',
            'path' => $this->projectPath,
        ]));

        $this->assertSame(0, $code);
        $this->assertFileExists($this->projectPath . '/.phpnomad/recipes/payout-service.json');
        $this->assertFileExists($this->projectPath . '/.phpnomad/templates/payout-service.php.tpl');
        $this->assertStringContainsString('created recipe=', $output->text());
    }

    public function testGeneratedRecipeMatchesSchemaShape(): void
    {
        $command = new RecipesInitCommand(new FakeOutput());
        $command->handle(new FakeInput(['name' => 'widget', 'path' => $this->projectPath]));

        $data = json_decode(
            (string) file_get_contents($this->projectPath . '/.phpnomad/recipes/widget.json'),
            true
        );

        $this->assertIsArray($data);
        $this->assertSame('widget', $data['name']);

        // Agent-facing metadata fields are stubbed with TODO guidance.
        foreach (['summary', 'description', 'problem'] as $field) {
            $this->assertStringContainsString('TODO', $data[$field]);
        }

        foreach (['appliesWhen', 'avoidWhen', 'tags'] as $field) {
            $this->assertIsArray($data[$field]);
            $this->assertNotEmpty($data[$field]);
        }

        // One files entry pointing at the generated template.
        $this->assertCount(1, $data['files']);
        $this->assertSame('widget', $data['files'][0]['template']);
        $this->assertStringContainsString('{{name}}', $data['files'][0]['path']);

        // Vars example present.
        $this->assertArrayHasKey('name', $data['vars']);
        $this->assertArrayHasKey('namespace', $data['vars']);

        // Only fields the recipe schema allows (additionalProperties is false).
        $schema = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/docs/recipe-schema.json'),
            true
        );
        $allowed = array_keys($schema['properties']);

        foreach (array_keys($data) as $key) {
            $this->assertContains($key, $allowed, "Field '$key' is not allowed by recipe-schema.json");
        }
    }

    public function testGeneratedRecipeLoadsThroughRecipeLoader(): void
    {
        $command = new RecipesInitCommand(new FakeOutput());
        $command->handle(new FakeInput(['name' => 'widget', 'path' => $this->projectPath]));

        $loader = new RecipeLoader(new KitDiscoverer());
        $recipe = $loader->load('widget', $this->projectPath);

        $this->assertSame('widget', $recipe->name);
        $this->assertNull($recipe->originKit);
        $this->assertCount(1, $recipe->files);
        $this->assertSame('widget', $recipe->files[0]->template);
        $this->assertNotEmpty($recipe->appliesWhen);
        $this->assertNotEmpty($recipe->avoidWhen);
        $this->assertNotEmpty($recipe->tags);
        $this->assertNotSame('', $recipe->problem);
    }

    public function testGeneratedTemplateRendersWithVars(): void
    {
        $command = new RecipesInitCommand(new FakeOutput());
        $command->handle(new FakeInput(['name' => 'widget', 'path' => $this->projectPath]));

        $renderer = new TemplateRenderer();
        $rendered = $renderer->render('widget', [
            'name' => 'PayoutService',
            'namespace' => 'Acme\\Payments',
        ], null, $this->projectPath);

        $this->assertStringContainsString('namespace Acme\\Payments;', $rendered);
        $this->assertStringContainsString('class PayoutService', $rendered);
        $this->assertStringNotContainsString('{{', $rendered);
    }

    public function testRefusesToOverwriteExistingRecipe(): void
    {
        mkdir($this->projectPath . '/.phpnomad/recipes', 0755, true);
        file_put_contents($this->projectPath . '/.phpnomad/recipes/widget.json', '{"name":"widget"}');

        $output = new FakeOutput();
        $command = new RecipesInitCommand($output);

        $code = $command->handle(new FakeInput(['name' => 'widget', 'path' => $this->projectPath]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Refusing to overwrite', $output->text());
        $this->assertSame('{"name":"widget"}', file_get_contents($this->projectPath . '/.phpnomad/recipes/widget.json'));
        $this->assertFileDoesNotExist($this->projectPath . '/.phpnomad/templates/widget.php.tpl');
    }

    public function testRefusesToOverwriteExistingTemplate(): void
    {
        mkdir($this->projectPath . '/.phpnomad/templates', 0755, true);
        file_put_contents($this->projectPath . '/.phpnomad/templates/widget.php.tpl', 'original');

        $output = new FakeOutput();
        $command = new RecipesInitCommand($output);

        $code = $command->handle(new FakeInput(['name' => 'widget', 'path' => $this->projectPath]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Refusing to overwrite', $output->text());
        $this->assertSame('original', file_get_contents($this->projectPath . '/.phpnomad/templates/widget.php.tpl'));
        $this->assertFileDoesNotExist($this->projectPath . '/.phpnomad/recipes/widget.json');
    }

    public function testRejectsInvalidName(): void
    {
        $output = new FakeOutput();
        $command = new RecipesInitCommand($output);

        foreach (['Bad Name', 'UpperCase', '../escape', ''] as $name) {
            $code = $command->handle(new FakeInput(['name' => $name, 'path' => $this->projectPath]));
            $this->assertSame(1, $code, "Name '$name' should be rejected");
        }

        $this->assertStringContainsString('Invalid recipe name', $output->text());
    }

    public function testRejectsMissingPath(): void
    {
        $output = new FakeOutput();
        $command = new RecipesInitCommand($output);

        $code = $command->handle(new FakeInput([
            'name' => 'widget',
            'path' => $this->projectPath . '/does-not-exist',
        ]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Path does not exist', $output->text());
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
