<?php

namespace PHPNomad\Cli\Scaffolder\Models;

final class RecipeFile
{
    /**
     * @param array<string, string> $vars Per-file var overrides
     */
    public function __construct(
        public readonly string $path,
        public readonly string $template,
        public readonly array $vars = []
    ) {
    }
}
