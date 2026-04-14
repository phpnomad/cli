<?php

namespace PHPNomad\Cli\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Models\GraphEdge;
use PHPNomad\Cli\Indexer\Models\GraphNode;

class GraphNodeAdapter
{
    /**
     * @param string $peerField 'target' for dependency-map, 'source' for dependents-map
     * @return array<string, mixed>
     */
    public function toArray(GraphNode $node, string $peerField = 'target'): array
    {
        return [
            'fqcn' => $node->fqcn,
            'file' => $node->file,
            'edges' => array_map(function (GraphEdge $edge) use ($peerField) {
                $arr = [
                    'type' => $edge->type,
                    $peerField => $edge->fqcn,
                ];

                if ($edge->via !== null) {
                    $arr['via'] = $edge->via;
                }

                return $arr;
            }, $node->edges),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param string $peerField 'target' for dependency-map, 'source' for dependents-map
     */
    public function fromArray(array $data, string $peerField = 'target'): GraphNode
    {
        $edges = array_map(function (array $edge) use ($peerField) {
            return new GraphEdge(
                type: $edge['type'],
                fqcn: $edge[$peerField],
                via: $edge['via'] ?? null
            );
        }, $data['edges'] ?? []);

        return new GraphNode(
            fqcn: $data['fqcn'],
            file: $data['file'] ?? null,
            edges: $edges
        );
    }
}
