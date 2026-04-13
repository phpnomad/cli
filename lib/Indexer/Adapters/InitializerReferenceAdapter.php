<?php

namespace PHPNomad\Cli\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Models\InitializerReference;

final class InitializerReferenceAdapter
{
    public function toArray(InitializerReference $ref): array
    {
        return [
            'fqcn' => $ref->fqcn,
            'source' => $ref->source,
            'isDynamic' => $ref->isDynamic,
        ];
    }

    public function fromArray(array $data): InitializerReference
    {
        return new InitializerReference(
            fqcn: $data['fqcn'] ?? null,
            source: $data['source'] ?? '',
            isDynamic: $data['isDynamic'] ?? false
        );
    }
}
