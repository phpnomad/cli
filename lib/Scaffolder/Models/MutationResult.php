<?php

namespace PHPNomad\Cli\Scaffolder\Models;

final class MutationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?string $manualEntry = null
    ) {
    }
}
