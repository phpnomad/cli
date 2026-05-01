<?php

namespace PHPNomad\Cli\Scaffolder\Models;

final class Kit
{
    public function __construct(
        public readonly string $vendor,
        public readonly string $packageName,
        public readonly string $recipesDir,
        public readonly string $templatesDir
    ) {
    }

    public function fullName(): string
    {
        return $this->vendor . '/' . $this->packageName;
    }
}
