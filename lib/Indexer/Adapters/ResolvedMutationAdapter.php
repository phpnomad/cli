<?php

namespace PHPNomad\Cli\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Models\ResolvedMutation;

class ResolvedMutationAdapter
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(ResolvedMutation $mutation): array
    {
        return [
            'fqcn' => $mutation->fqcn,
            'file' => $mutation->file,
            'actions' => $mutation->actions,
            'usesAdapter' => $mutation->usesAdapter,
            'adapterClass' => $mutation->adapterClass,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): ResolvedMutation
    {
        return new ResolvedMutation(
            fqcn: $data['fqcn'],
            file: $data['file'],
            actions: $data['actions'] ?? [],
            usesAdapter: $data['usesAdapter'] ?? false,
            adapterClass: $data['adapterClass'] ?? null,
        );
    }
}
