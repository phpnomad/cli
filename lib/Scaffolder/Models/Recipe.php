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
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $vars = [],
        public readonly array $requires = [],
        public readonly array $files = [],
        public readonly array $registrations = [],
        public readonly array $recipes = []
    ) {
    }
}
