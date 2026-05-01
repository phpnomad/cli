<?php

namespace PHPNomad\Cli\Scaffolder\Models;

final class Recipe
{
    /**
     * @param RecipeVar[] $vars
     * @param RecipeRequirement[] $requires
     * @param RecipeFile[] $files
     * @param RecipeRegistration[] $registrations
     * @param RecipeReference[] $recipes Child recipes to execute (recipe stacking)
     * @param array<string, string[]> $synonyms Domain-keyed vocabulary, e.g. ["wordpress" => ["custom post type", "CPT"]]
     * @param string[] $appliesWhen
     * @param string[] $avoidWhen
     * @param string[] $examples
     * @param string[] $tags
     * @param string[] $outputs
     * @param string[] $postApply
     * @param array<int, array{recipe: string, relationship: string}> $relatedPatterns
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $vars = [],
        public readonly array $requires = [],
        public readonly array $files = [],
        public readonly array $registrations = [],
        public readonly array $recipes = [],
        public readonly ?Kit $originKit = null,
        public readonly string $summary = '',
        public readonly string $problem = '',
        public readonly array $appliesWhen = [],
        public readonly array $avoidWhen = [],
        public readonly array $synonyms = [],
        public readonly array $examples = [],
        public readonly array $tags = [],
        public readonly string $kind = 'scaffolding',
        public readonly string $tradeoffs = '',
        public readonly array $relatedPatterns = [],
        public readonly string $stability = '',
        public readonly array $outputs = [],
        public readonly array $postApply = []
    ) {
    }
}
