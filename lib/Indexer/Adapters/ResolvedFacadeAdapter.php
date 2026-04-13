<?php

namespace PHPNomad\Cli\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Models\ResolvedFacade;

class ResolvedFacadeAdapter
{
    public function toArray(ResolvedFacade $facade): array
    {
        return [
            'fqcn' => $facade->fqcn,
            'file' => $facade->file,
            'proxiedInterface' => $facade->proxiedInterface,
        ];
    }

    public function fromArray(array $data): ResolvedFacade
    {
        return new ResolvedFacade(
            fqcn: $data['fqcn'],
            file: $data['file'],
            proxiedInterface: $data['proxiedInterface'] ?? null,
        );
    }
}
