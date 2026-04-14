<?php

namespace PHPNomad\Cli\Scaffolder\Models;

final class RecipeReference
{
    /**
     * @param array<string, string> $vars Var overrides to pass to the child recipe
     */
    public function __construct(
        public readonly string $recipe,
        public readonly array $vars = []
    ) {
    }
}
