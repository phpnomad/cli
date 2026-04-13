<?php

namespace PHPNomad\Cli\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Models\ResolvedTable;

final class ResolvedTableAdapter
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(ResolvedTable $table): array
    {
        return [
            'fqcn' => $table->fqcn,
            'file' => $table->file,
            'tableName' => $table->tableName,
            'columns' => $table->columns,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): ResolvedTable
    {
        return new ResolvedTable(
            fqcn: $data['fqcn'],
            file: $data['file'] ?? '',
            tableName: $data['tableName'] ?? null,
            columns: $data['columns'] ?? []
        );
    }
}
