<?php

namespace PHPNomad\Cli\Indexer\Models;

final class ResolvedCommand
{
    public function __construct(
        public readonly string $fqcn,
        public readonly string $file,
        public readonly ?string $signature,
        public readonly ?string $description
    ) {
    }
}
