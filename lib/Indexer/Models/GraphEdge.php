<?php

namespace PHPNomad\Cli\Indexer\Models;

final class GraphEdge
{
    public function __construct(
        public readonly string $type,
        public readonly string $fqcn,
        public readonly ?string $via = null
    ) {
    }
}
