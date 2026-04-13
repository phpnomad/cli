<?php

namespace PHPNomad\Cli\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Models\IndexedBinding;

final class IndexedBindingAdapter
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(IndexedBinding $binding): array
    {
        return [
            'concrete' => $binding->concrete,
            'abstracts' => $binding->abstracts,
            'source' => $binding->source,
            'sourceFile' => $binding->sourceFile,
            'bindingType' => $binding->bindingType,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): IndexedBinding
    {
        return new IndexedBinding(
            concrete: $data['concrete'],
            abstracts: $data['abstracts'] ?? [],
            source: $data['source'] ?? '',
            sourceFile: $data['sourceFile'] ?? '',
            bindingType: $data['bindingType'] ?? 'declarative'
        );
    }
}
