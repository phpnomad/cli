<?php

namespace PHPNomad\Cli\Tests\Scaffolder;

use PHPNomad\Cli\Scaffolder\KitDiscoverer;
use PHPNomad\Cli\Scaffolder\RecipeLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RecipeLoaderTest extends TestCase
{
    private string $projectPath;
    private RecipeLoader $loader;

    protected function setUp(): void
    {
        $this->projectPath = sys_get_temp_dir() . '/phpnomad-loader-test-' . uniqid();
        mkdir($this->projectPath, 0755, true);

        // Stand up a fake phpnomad/core-recipes kit so kit-published recipe lookups have something to find.
        $this->installKit('phpnomad', 'core-recipes', [
            'listener.json' => [
                'name' => 'listener',
                'description' => 'Generates an event listener',
                'vars' => [
                    'name' => ['type' => 'string', 'description' => 'Listener class name'],
                    'event' => ['type' => 'string', 'description' => 'Event FQCN'],
                    'initializer' => ['type' => 'string', 'description' => 'Initializer FQCN'],
                ],
                'files' => [
                    ['path' => 'lib/Listeners/{{name}}.php', 'template' => 'listener'],
                ],
                'registrations' => [
                    [
                        'initializer' => '{{initializer}}',
                        'method' => 'getListeners',
                        'interface' => 'PHPNomad\\Loader\\Interfaces\\HasListeners',
                        'type' => 'map',
                    ],
                ],
            ],
            'event.json' => [
                'name' => 'event',
                'vars' => [
                    'name' => ['type' => 'string', 'description' => 'Event class name'],
                    'eventId' => ['type' => 'string', 'description' => 'Event ID'],
                ],
                'files' => [
                    ['path' => 'lib/Events/{{name}}.php', 'template' => 'event'],
                ],
            ],
            'task.json' => [
                'name' => 'task',
                'vars' => [
                    'name' => ['type' => 'string', 'description' => 'Task class name'],
                ],
                'files' => [
                    ['path' => 'lib/Tasks/{{name}}.php', 'template' => 'task'],
                ],
            ],
        ]);

        $this->loader = new RecipeLoader(new KitDiscoverer());
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectPath);
    }

    public function testLoadKitRecipe(): void
    {
        $recipe = $this->loader->load('phpnomad/listener', $this->projectPath);

        $this->assertSame('listener', $recipe->name);
        $this->assertNotEmpty($recipe->vars);
        $this->assertNotEmpty($recipe->files);
        $this->assertNotEmpty($recipe->registrations);
        $this->assertNotNull($recipe->originKit);
        $this->assertSame('phpnomad', $recipe->originKit->vendor);
    }

    public function testLoadFromFilePath(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'recipe_') . '.json';
        file_put_contents($tmpFile, json_encode([
            'name' => 'inline-recipe',
            'vars' => ['name' => ['type' => 'string', 'description' => '']],
        ]));

        try {
            $recipe = $this->loader->load($tmpFile);
            $this->assertSame('inline-recipe', $recipe->name);
            $this->assertNull($recipe->originKit);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testVarsParsedCorrectly(): void
    {
        $recipe = $this->loader->load('phpnomad/listener', $this->projectPath);

        $varNames = array_map(fn($v) => $v->name, $recipe->vars);

        $this->assertContains('name', $varNames);
        $this->assertContains('event', $varNames);
        $this->assertContains('initializer', $varNames);
    }

    public function testFilesParsedCorrectly(): void
    {
        $recipe = $this->loader->load('phpnomad/listener', $this->projectPath);

        $this->assertCount(1, $recipe->files);
        $this->assertSame('lib/Listeners/{{name}}.php', $recipe->files[0]->path);
        $this->assertSame('listener', $recipe->files[0]->template);
    }

    public function testRegistrationsParsedCorrectly(): void
    {
        $recipe = $this->loader->load('phpnomad/listener', $this->projectPath);

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

        $this->loader->load('phpnomad/nonexistent', $this->projectPath);
    }

    public function testBareNameWithNoProjectLocalThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recipe not found');

        $this->loader->load('listener', $this->projectPath);
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

    public function testProjectLocalRecipeLoadsByBareName(): void
    {
        $recipesDir = $this->projectPath . '/.phpnomad/recipes';
        mkdir($recipesDir, 0755, true);
        file_put_contents($recipesDir . '/custom.json', json_encode([
            'name' => 'custom-recipe',
            'description' => 'Project-local recipe',
            'files' => [
                ['path' => 'lib/{{name}}.php', 'template' => 'custom'],
            ],
        ]));

        $recipe = $this->loader->load('custom', $this->projectPath);

        $this->assertSame('custom-recipe', $recipe->name);
        $this->assertNull($recipe->originKit);
    }

    public function testProjectLocalRecipeFoundFromSubpackageDirectory(): void
    {
        $recipesDir = $this->projectPath . '/.phpnomad/recipes';
        $packageDir = $this->projectPath . '/mu-plugins/siren-core';
        mkdir($recipesDir, 0755, true);
        mkdir($packageDir, 0755, true);

        file_put_contents($recipesDir . '/task.json', json_encode([
            'name' => 'siren-task',
            'description' => 'Stack elevator task recipe',
            'files' => [
                ['path' => 'lib/{{domain}}/Core/Tasks/{{name}}.php', 'template' => 'task'],
            ],
        ]));

        $recipe = $this->loader->load('task', $packageDir);

        $this->assertSame('siren-task', $recipe->name);
        $this->assertSame('lib/{{domain}}/Core/Tasks/{{name}}.php', $recipe->files[0]->path);
    }

    public function testNewMetadataFieldsParsed(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'recipe_') . '.json';
        file_put_contents($tmpFile, json_encode([
            'name' => 'metadata-recipe',
            'summary' => 'A recipe with full metadata',
            'problem' => 'You need a thing to test parsing',
            'appliesWhen' => ['When you are testing', 'When you need data'],
            'avoidWhen' => ['When you are not testing'],
            'synonyms' => ['general' => ['test thing'], 'wordpress' => ['plugin thing']],
            'examples' => ['I need a test thing'],
            'tags' => ['test', 'metadata'],
            'kind' => 'scaffolding',
            'tradeoffs' => 'Adds boilerplate',
            'relatedPatterns' => [
                ['recipe' => 'phpnomad/datastore', 'relationship' => 'often-paired'],
            ],
            'stability' => 'experimental',
            'outputs' => ['A test class'],
            'postApply' => ['Run the tests'],
            'vars' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Class name',
                    'example' => 'TestThing',
                    'aiHint' => 'Use PascalCase, no Test prefix',
                ],
            ],
        ]));

        try {
            $recipe = $this->loader->load($tmpFile);

            $this->assertSame('A recipe with full metadata', $recipe->summary);
            $this->assertSame('You need a thing to test parsing', $recipe->problem);
            $this->assertSame(['When you are testing', 'When you need data'], $recipe->appliesWhen);
            $this->assertSame(['When you are not testing'], $recipe->avoidWhen);
            $this->assertSame(['general' => ['test thing'], 'wordpress' => ['plugin thing']], $recipe->synonyms);
            $this->assertSame(['I need a test thing'], $recipe->examples);
            $this->assertSame(['test', 'metadata'], $recipe->tags);
            $this->assertSame('scaffolding', $recipe->kind);
            $this->assertSame('Adds boilerplate', $recipe->tradeoffs);
            $this->assertSame([['recipe' => 'phpnomad/datastore', 'relationship' => 'often-paired']], $recipe->relatedPatterns);
            $this->assertSame('experimental', $recipe->stability);
            $this->assertSame(['A test class'], $recipe->outputs);
            $this->assertSame(['Run the tests'], $recipe->postApply);

            $this->assertCount(1, $recipe->vars);
            $this->assertSame('TestThing', $recipe->vars[0]->example);
            $this->assertSame('Use PascalCase, no Test prefix', $recipe->vars[0]->aiHint);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testFileOverwriteFlagParsed(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'recipe_') . '.json';
        file_put_contents($tmpFile, json_encode([
            'name' => 'overwrite-recipe',
            'files' => [
                ['path' => 'composer.json', 'template' => 'composer.json.tpl', 'overwrite' => true],
                ['path' => 'lib/Foo.php', 'template' => 'foo'],
            ],
        ]));

        try {
            $recipe = $this->loader->load($tmpFile);

            $this->assertCount(2, $recipe->files);
            $this->assertTrue($recipe->files[0]->overwrite);
            $this->assertFalse($recipe->files[1]->overwrite);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testKindDefaultsToScaffolding(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'recipe_') . '.json';
        file_put_contents($tmpFile, json_encode(['name' => 'plain']));

        try {
            $recipe = $this->loader->load($tmpFile);
            $this->assertSame('scaffolding', $recipe->kind);
        } finally {
            unlink($tmpFile);
        }
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
