<?php

namespace PHPNomad\Cli\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Models\ResolvedGraphQLType;

final class ResolvedGraphQLTypeAdapter
{
    public function toArray(ResolvedGraphQLType $type): array
    {
        return [
            'fqcn' => $type->fqcn,
            'file' => $type->file,
            'sdl' => $type->sdl,
            'resolvers' => $type->resolvers,
        ];
    }

    public function fromArray(array $data): ResolvedGraphQLType
    {
        return new ResolvedGraphQLType(
            fqcn: $data['fqcn'],
            file: $data['file'] ?? '',
            sdl: $data['sdl'] ?? null,
            resolvers: $data['resolvers'] ?? []
        );
    }
}
