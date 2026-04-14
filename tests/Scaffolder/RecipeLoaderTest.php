<?php

namespace PHPNomad\Cli\Tests\Scaffolder;

use PHPNomad\Cli\Scaffolder\RecipeLoader;
use PHPNomad\Cli\Scaffolder\Models\Recipe;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RecipeLoaderTest extends TestCase
{
    private RecipeLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new RecipeLoader();
    }

    public function testLoadBuiltinRecipe(): void
    {
        $recipe = $this->loader->load('listener');

        $this->assertSame('listener', $recipe->name);
        $this->assertNotEmpty($recipe->vars);
        $this->assertNotEmpty($recipe->files);
        $this->assertNotEmpty($recipe->registrations);
    }

    public function testLoadFromFilePath(): void
    {
        $path = __DIR__ . '/../../lib/Scaffolder/Recipes/event.json';
        $recipe = $this->loader->load($path);

        $this->assertSame('event', $recipe->name);
        $this->assertCount(2, $recipe->vars);
    }

    public function testVarsParsedCorrectly(): void
    {
        $recipe = $this->loader->load('listener');

        $varNames = array_map(fn($v) => $v->name, $recipe->vars);

        $this->assertContains('name', $varNames);
        $this->assertContains('event', $varNames);
        $this->assertContains('initializer', $varNames);
    }

    public function testFilesParsedCorrectly(): void
    {
        $recipe = $this->loader->load('listener');

        $this->assertCount(1, $recipe->files);
        $this->assertSame('lib/Listeners/{{name}}.php', $recipe->files[0]->path);
        $this->assertSame('listener', $recipe->files[0]->template);
    }

    public function testRegistrationsParsedCorrectly(): void
    {
        $recipe = $this->loader->load('listener');

        $this->assertCount(1, $recipe->registrations);
        $reg = $recipe->registrations[0];
        $this->assertSame('{{initializer}}', $reg->initializer);
        $this->assertSame('getListeners', $reg->method);
        $this->assertSame('map', $reg->type);
    }

    public function testMissingRecipeThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recipe not found');

        $this->loader->load('nonexistent-recipe');
    }

    public function testInvalidJsonThrows(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'recipe_');
        file_put_contents($tmpFile, 'not valid json');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid JSON');

            $this->loader->load($tmpFile);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testMissingNameFieldThrows(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'recipe_') . '.json';
        file_put_contents($tmpFile, json_encode(['files' => []]));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('"name"');

            $this->loader->load($tmpFile);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testPerFileVarsParsed(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'recipe_') . '.json';
        file_put_contents($tmpFile, json_encode([
            'name' => 'test',
            'files' => [
                [
                    'path' => 'lib/Test.php',
                    'template' => 'test',
                    'vars' => ['className' => 'Override'],
                ],
            ],
        ]));

        try {
            $recipe = $this->loader->load($tmpFile);

            $this->assertSame(['className' => 'Override'], $recipe->files[0]->vars);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testProjectLocalRecipeOverridesBuiltin(): void
    {
        $tmpDir = sys_get_temp_dir() . '/phpnomad-test-' . uniqid();
        $recipesDir = $tmpDir . '/.phpnomad/recipes';
        mkdir($recipesDir, 0755, true);
        file_put_contents($recipesDir . '/task.json', json_encode([
            'name' => 'custom-task',
            'description' => 'Project-local task recipe',
            'files' => [
                [
                    'path' => 'lib/{{domain}}/Core/Tasks/{{name}}.php',
                    'template' => 'task',
                ],
            ],
        ]));

        try {
            $recipe = $this->loader->load('task', $tmpDir);

            $this->assertSame('custom-task', $recipe->name);
            $this->assertSame('lib/{{domain}}/Core/Tasks/{{name}}.php', $recipe->files[0]->path);
        } finally {
            unlink($recipesDir . '/task.json');
            rmdir($recipesDir);
            rmdir($tmpDir . '/.phpnomad');
            rmdir($tmpDir);
        }
    }

    public function testFallsBackToBuiltinWhenNoProjectLocal(): void
    {
        $tmpDir = sys_get_temp_dir() . '/phpnomad-test-' . uniqid();
        mkdir($tmpDir);

        try {
            $recipe = $this->loader->load('task', $tmpDir);

            $this->assertSame('task', $recipe->name);
        } finally {
            rmdir($tmpDir);
        }
    }

    public function testProjectLocalRecipeIgnoredForExplicitPaths(): void
    {
        $tmpDir = sys_get_temp_dir() . '/phpnomad-test-' . uniqid();
        $recipesDir = $tmpDir . '/.phpnomad/recipes';
        mkdir($recipesDir, 0755, true);
        file_put_contents($recipesDir . '/task.json', json_encode([
            'name' => 'custom-task',
            'files' => [],
        ]));

        $builtinPath = __DIR__ . '/../../lib/Scaffolder/Recipes/task.json';

        try {
            // Explicit path should bypass project-local resolution
            $recipe = $this->loader->load($builtinPath, $tmpDir);

            $this->assertSame('task', $recipe->name);
        } finally {
            unlink($recipesDir . '/task.json');
            rmdir($recipesDir);
            rmdir($tmpDir . '/.phpnomad');
            rmdir($tmpDir);
        }
    }

    public function testProjectPathNullFallsBackToBuiltin(): void
    {
        $recipe = $this->loader->load('task', null);

        $this->assertSame('task', $recipe->name);
    }

    public function testProjectLocalRecipeFoundFromSubpackageDirectory(): void
    {
        // Simulates Siren's multi-package structure:
        // project-root/.phpnomad/recipes/task.json exists
        // but --path resolves to project-root/mu-plugins/siren-core/ (the package root)
        $tmpDir = sys_get_temp_dir() . '/phpnomad-test-' . uniqid();
        $recipesDir = $tmpDir . '/.phpnomad/recipes';
        $packageDir = $tmpDir . '/mu-plugins/siren-core';
        mkdir($recipesDir, 0755, true);
        mkdir($packageDir, 0755, true);

        file_put_contents($recipesDir . '/task.json', json_encode([
            'name' => 'siren-task',
            'description' => 'Stack elevator task recipe',
            'files' => [
                [
                    'path' => 'lib/{{domain}}/Core/Tasks/{{name}}.php',
                    'template' => 'task',
                ],
            ],
        ]));

        try {
            // Pass the package directory as projectPath — loader should walk up to find .phpnomad/recipes/
            $recipe = $this->loader->load('task', $packageDir);

            $this->assertSame('siren-task', $recipe->name);
            $this->assertSame('lib/{{domain}}/Core/Tasks/{{name}}.php', $recipe->files[0]->path);
        } finally {
            unlink($recipesDir . '/task.json');
            rmdir($recipesDir);
            rmdir($tmpDir . '/.phpnomad');
            rmdir($packageDir);
            rmdir($tmpDir . '/mu-plugins');
            rmdir($tmpDir);
        }
    }
}
