<?php

namespace PHPNomad\Cli\Indexer\Models;

final class OrphanEntry
{
    public function __construct(
        public readonly string $fqcn,
        public readonly string $file
    ) {
    }
}
