<?php

namespace PHPNomad\Cli\Scaffolder\Models;

final class Recipe
{
    /**
     * @param RecipeVar[] $vars
     * @param RecipeRequirement[] $requires
     * @param RecipeFile[] $files
     * @param RecipeRegistration[] $registrations
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $vars = [],
        public readonly array $requires = [],
        public readonly array $files = [],
        public readonly array $registrations = []
    ) {
    }
}
