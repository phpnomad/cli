<?php

namespace PHPNomad\Cli\Indexer\Models;

final class GraphNode
{
    /**
     * @param string $fqcn
     * @param ?string $file Null for vendor/external types
     * @param GraphEdge[] $edges
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly ?string $file,
        public readonly array $edges
    ) {
    }
}
