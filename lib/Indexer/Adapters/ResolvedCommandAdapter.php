<?php

namespace PHPNomad\Cli\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Models\ResolvedCommand;

final class ResolvedCommandAdapter
{
    public function toArray(ResolvedCommand $command): array
    {
        return [
            'fqcn' => $command->fqcn,
            'file' => $command->file,
            'signature' => $command->signature,
            'description' => $command->description,
        ];
    }

    public function fromArray(array $data): ResolvedCommand
    {
        return new ResolvedCommand(
            fqcn: $data['fqcn'],
            file: $data['file'] ?? '',
            signature: $data['signature'] ?? null,
            description: $data['description'] ?? null
        );
    }
}
