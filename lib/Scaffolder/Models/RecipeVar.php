<?php

namespace PHPNomad\Cli\Scaffolder\Models;

final class RecipeVar
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $description
    ) {
    }
}
