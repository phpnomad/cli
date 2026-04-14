<?php

namespace PHPNomad\Cli\Scaffolder\Models;

final class RecipeRequirement
{
    public function __construct(
        public readonly string $type,
        public readonly string $value
    ) {
    }
}
