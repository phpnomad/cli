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
}
