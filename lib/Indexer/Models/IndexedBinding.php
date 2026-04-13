<?php

namespace PHPNomad\Cli\Indexer\Models;

final class IndexedBinding
{
    /**
     * @param string $concrete
     * @param string[] $abstracts
     * @param string $source
     * @param string $sourceFile
     * @param string $bindingType "declarative" or "imperative"
     */
    public function __construct(
        public readonly string $concrete,
        public readonly array $abstracts,
        public readonly string $source,
        public readonly string $sourceFile,
        public readonly string $bindingType
    ) {
    }
}
