<?php

namespace PHPNomad\Cli\Tests\Scaffolder;

use PHPNomad\Cli\Scaffolder\RecipeValidator;
use PHPUnit\Framework\TestCase;

class RecipeValidatorTest extends TestCase
{
    private RecipeValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new RecipeValidator();
    }

    public function testMinimalRecipeValidates(): void
    {
        $errors = $this->validator->validateData(['name' => 'datastore']);

        $this->assertSame([], $errors);
    }

    public function testMissingNameProducesError(): void
    {
        $errors = $this->validator->validateData([]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('"name"', $errors[0]);
    }

    public function testEmptyNameProducesError(): void
    {
        $errors = $this->validator->validateData(['name' => '']);

        $this->assertNotEmpty($errors);
    }

    public function testInvalidKindRejected(): void
    {
        $errors = $this->validator->validateData(['name' => 'x', 'kind' => 'something-else']);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('kind', $errors[0]);
    }

    public function testValidKindAccepted(): void
    {
        foreach (['scaffolding', 'advisory', 'composite'] as $kind) {
            $errors = $this->validator->validateData(['name' => 'x', 'kind' => $kind]);
            $this->assertSame([], $errors, "Kind '$kind' should validate");
        }
    }

    public function testInvalidStabilityRejected(): void
    {
        $errors = $this->validator->validateData(['name' => 'x', 'stability' => 'wobbly']);

        $this->assertNotEmpty($errors);
    }

    public function testSummaryLengthEnforced(): void
    {
        $errors = $this->validator->validateData([
            'name' => 'x',
            'summary' => str_repeat('a', 101),
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('100 characters', $errors[0]);
    }

    public function testStringArrayWithNonStringFails(): void
    {
        $errors = $this->validator->validateData([
            'name' => 'x',
            'tags' => ['ok', 123],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('tags', $errors[0]);
    }

    public function testSynonymsMustBeObjectOfArrays(): void
    {
        $errors = $this->validator->validateData([
            'name' => 'x',
            'synonyms' => ['wordpress' => 'CPT'],
        ]);

        $this->assertNotEmpty($errors);
    }

    public function testSynonymsValidShape(): void
    {
        $errors = $this->validator->validateData([
            'name' => 'x',
            'synonyms' => [
                'wordpress' => ['custom post type', 'CPT'],
                'general' => ['entity store'],
            ],
        ]);

        $this->assertSame([], $errors);
    }

    public function testRelatedPatternsMissingFields(): void
    {
        $errors = $this->validator->validateData([
            'name' => 'x',
            'relatedPatterns' => [
                ['recipe' => 'phpnomad/datastore'],
            ],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('relationship', $errors[0]);
    }

    public function testFileMissingPathFails(): void
    {
        $errors = $this->validator->validateData([
            'name' => 'x',
            'files' => [['template' => 'datastore']],
        ]);

        $this->assertNotEmpty($errors);
    }

    public function testRegistrationMissingFieldsFails(): void
    {
        $errors = $this->validator->validateData([
            'name' => 'x',
            'registrations' => [
                ['initializer' => 'X', 'method' => 'Y', 'interface' => 'Z'],
            ],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('type', $errors[0]);
    }

    public function testRegistrationInvalidTypeRejected(): void
    {
        $errors = $this->validator->validateData([
            'name' => 'x',
            'registrations' => [
                ['initializer' => 'X', 'method' => 'Y', 'interface' => 'Z', 'type' => 'invalid'],
            ],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('map, list', $errors[0]);
    }

    public function testRecipeReferenceMissingRecipeFails(): void
    {
        $errors = $this->validator->validateData([
            'name' => 'x',
            'recipes' => [['vars' => []]],
        ]);

        $this->assertNotEmpty($errors);
    }

    public function testValidateFileNotFound(): void
    {
        $errors = $this->validator->validate('/nonexistent.json');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('does not exist', $errors[0]);
    }

    public function testValidateInvalidJson(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'recipe_') . '.json';
        file_put_contents($tmp, '{invalid');

        try {
            $errors = $this->validator->validate($tmp);
            $this->assertNotEmpty($errors);
            $this->assertStringContainsString('Invalid JSON', $errors[0]);
        } finally {
            unlink($tmp);
        }
    }

    public function testFullyMetadatedRecipeValidates(): void
    {
        $errors = $this->validator->validateData([
            'name' => 'datastore',
            'summary' => 'Typed CRUD access layer',
            'problem' => 'You have a recurring entity that needs persistent storage',
            'description' => 'Long description here',
            'tradeoffs' => 'Adds three files',
            'kind' => 'scaffolding',
            'stability' => 'stable',
            'appliesWhen' => ['You have a stable entity'],
            'avoidWhen' => ['Data is transient'],
            'examples' => ['I need to track customer orders'],
            'tags' => ['data', 'core'],
            'outputs' => ['A {name}Datastore class'],
            'postApply' => ['Implement the TODO methods'],
            'synonyms' => ['general' => ['entity store']],
            'relatedPatterns' => [['recipe' => 'phpnomad/table', 'relationship' => 'often-paired']],
            'vars' => [
                'name' => ['type' => 'string', 'description' => 'Class name', 'example' => 'Order', 'aiHint' => 'PascalCase'],
            ],
            'requires' => [['type' => 'binding', 'value' => 'X']],
            'files' => [['path' => 'lib/{{name}}.php', 'template' => 'datastore']],
            'registrations' => [['initializer' => 'X', 'method' => 'Y', 'interface' => 'Z', 'type' => 'map']],
            'recipes' => [['recipe' => 'phpnomad/table']],
        ]);

        $this->assertSame([], $errors);
    }
}
