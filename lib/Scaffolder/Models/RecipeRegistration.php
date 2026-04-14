<?php

namespace PHPNomad\Cli\Scaffolder\Models;

final class RecipeRegistration
{
    public function __construct(
        public readonly string $initializer,
        public readonly string $method,
        public readonly string $interface,
        public readonly string $type,
        public readonly ?string $key = null,
        public readonly ?string $value = null
    ) {
    }
}
