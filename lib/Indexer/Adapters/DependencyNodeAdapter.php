<?php

namespace PHPNomad\Cli\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Models\DependencyNode;

final class DependencyNodeAdapter
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(DependencyNode $node): array
    {
        return [
            'abstract' => $node->abstract,
            'concrete' => $node->concrete,
            'source' => $node->source,
            'resolutionType' => $node->resolutionType,
            'dependencies' => array_map(fn($d) => $this->toArray($d), $node->dependencies),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): DependencyNode
    {
        return new DependencyNode(
            abstract: $data['abstract'],
            concrete: $data['concrete'] ?? null,
            source: $data['source'] ?? null,
            resolutionType: $data['resolutionType'] ?? 'unresolved',
            dependencies: array_map(fn($d) => $this->fromArray($d), $data['dependencies'] ?? [])
        );
    }
}
