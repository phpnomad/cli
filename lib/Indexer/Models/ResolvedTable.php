<?php

namespace PHPNomad\Cli\Indexer\Models;

final class ResolvedTable
{
    /**
     * @param string $fqcn
     * @param string $file
     * @param ?string $tableName From getUnprefixedName() or getName(), null if couldn't parse
     * @param list<array{name: string, type: string, typeArgs: ?list<int|float>, attributes: list<string>, factory: ?string}> $columns
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $file,
        public readonly ?string $tableName,
        public readonly array $columns
    ) {
    }
}
