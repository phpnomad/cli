<?php

namespace PHPNomad\Cli\Indexer\Models;

final class ResolvedTable
{
    /**
     * @param string $fqcn
     * @param string $file
     * @param ?string $tableName From getUnprefixedName() or getName(), null if couldn't parse
     * @param array $columns Array of ['name' => string, 'type' => string, 'typeArgs' => ?array, 'attributes' => string[], 'factory' => ?string]
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $file,
        public readonly ?string $tableName,
        public readonly array $columns
    ) {
    }
}
