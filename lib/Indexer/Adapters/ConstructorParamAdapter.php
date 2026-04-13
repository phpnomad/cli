<?php

namespace PHPNomad\Cli\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Models\ConstructorParam;

final class ConstructorParamAdapter
{
    public function toArray(ConstructorParam $param): array
    {
        return [
            'name' => $param->name,
            'type' => $param->type,
            'isBuiltin' => $param->isBuiltin,
        ];
    }

    public function fromArray(array $data): ConstructorParam
    {
        return new ConstructorParam(
            name: $data['name'],
            type: $data['type'] ?? null,
            isBuiltin: $data['isBuiltin'] ?? false
        );
    }
}
