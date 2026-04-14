<?php

namespace PHPNomad\Cli\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Models\OrphanEntry;

class OrphanEntryAdapter
{
    /**
     * @return array<string, string>
     */
    public function toArray(OrphanEntry $entry): array
    {
        return [
            'fqcn' => $entry->fqcn,
            'file' => $entry->file,
        ];
    }

    /**
     * @param array<string, string> $data
     */
    public function fromArray(array $data): OrphanEntry
    {
        return new OrphanEntry(
            fqcn: $data['fqcn'],
            file: $data['file']
        );
    }
}
