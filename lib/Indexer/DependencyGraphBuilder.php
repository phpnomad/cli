<?php

namespace PHPNomad\Cli\Indexer;

use PHPNomad\Cli\Indexer\Models\GraphEdge;
use PHPNomad\Cli\Indexer\Models\GraphNode;
use PHPNomad\Cli\Indexer\Models\IndexedBinding;
use PHPNomad\Cli\Indexer\Models\OrphanEntry;
use PHPNomad\Cli\Indexer\Models\ProjectIndex;

class DependencyGraphBuilder
{
    private const INVERTED_TYPES = [
        'injects' => 'injected-by',
        'implements' => 'implemented-by',
        'extends' => 'extended-by',
        'uses-trait' => 'trait-used-by',
        'listens-to' => 'listened-by',
        'handles-task' => 'task-handled-by',
        'proxies' => 'proxied-by',
        'resolves-to' => 'resolved-from',
        'mutates-via' => 'adapter-for',
    ];

    /**
     * Build both dependency graph directions and orphan list from the project index.
     *
     * @return array{dependencyMap: array<string, GraphNode>, dependentsMap: array<string, GraphNode>, orphans: OrphanEntry[]}
     */
    public function build(ProjectIndex $index): array
    {
        $rawEdges = $this->collectEdges($index);

        $dependencyMap = $this->buildDependencyMap($rawEdges, $index);
        $dependentsMap = $this->buildDependentsMap($rawEdges, $index);
        $orphans = $this->findOrphans($index, $dependencyMap, $dependentsMap);

        return [
            'dependencyMap' => $dependencyMap,
            'dependentsMap' => $dependentsMap,
            'orphans' => $orphans,
        ];
    }

    /**
     * Collect all raw edges from the project index.
     *
     * @return list<array{source: string, type: string, target: string, via: ?string}>
     */
    protected function collectEdges(ProjectIndex $index): array
    {
        $edges = [];

        // Class-level relationships
        foreach ($index->classes as $class) {
            // Constructor injection
            foreach ($class->constructorParams as $param) {
                if (!$param->isBuiltin && $param->type !== null) {
                    $edges[] = ['source' => $class->fqcn, 'type' => 'injects', 'target' => $param->type, 'via' => null];
                }
            }

            // Interface implementation
            foreach ($class->implements as $iface) {
                $edges[] = ['source' => $class->fqcn, 'type' => 'implements', 'target' => $iface, 'via' => null];
            }

            // Inheritance
            if ($class->parentClass !== null) {
                $edges[] = ['source' => $class->fqcn, 'type' => 'extends', 'target' => $class->parentClass, 'via' => null];
            }

            // Trait usage
            foreach ($class->traits as $trait) {
                $edges[] = ['source' => $class->fqcn, 'type' => 'uses-trait', 'target' => $trait, 'via' => null];
            }
        }

        // Listener relationships
        foreach ($index->initializers as $init) {
            foreach ($init->listeners as $eventFqcn => $handlerFqcns) {
                foreach ($handlerFqcns as $handlerFqcn) {
                    $edges[] = ['source' => $handlerFqcn, 'type' => 'listens-to', 'target' => $eventFqcn, 'via' => null];
                }
            }
        }

        // Task handler relationships
        foreach ($index->resolvedTaskHandlers as $handler) {
            $edges[] = ['source' => $handler->handlerFqcn, 'type' => 'handles-task', 'target' => $handler->taskClass, 'via' => null];
        }

        // Facade proxy relationships
        foreach ($index->resolvedFacades as $facade) {
            if ($facade->proxiedInterface !== null) {
                $edges[] = ['source' => $facade->fqcn, 'type' => 'proxies', 'target' => $facade->proxiedInterface, 'via' => null];
            }
        }

        // Mutation adapter relationships
        foreach ($index->resolvedMutations as $mutation) {
            if ($mutation->adapterClass !== null) {
                $edges[] = ['source' => $mutation->fqcn, 'type' => 'mutates-via', 'target' => $mutation->adapterClass, 'via' => null];
            }
        }

        // Binding relationships (resolves-to)
        $bindingMap = $this->buildBindingMap($index);

        foreach ($bindingMap as $abstract => $binding) {
            $edges[] = ['source' => $abstract, 'type' => 'resolves-to', 'target' => $binding->concrete, 'via' => $binding->source];
        }

        return $edges;
    }

    /**
     * Build the binding map from applications and initializers.
     * Mirrors DependencyResolver::buildBindingMap logic.
     *
     * @return array<string, IndexedBinding>
     */
    protected function buildBindingMap(ProjectIndex $index): array
    {
        $map = [];

        foreach ($index->applications as $app) {
            foreach ($app->preBootstrapBindings as $binding) {
                foreach ($binding->abstracts as $abstract) {
                    $map[$abstract] = $binding;
                }
            }

            foreach ($app->getAllInitializerReferences() as $ref) {
                if ($ref->fqcn === null || $ref->isDynamic) {
                    continue;
                }

                $init = $index->initializers[$ref->fqcn] ?? null;

                if ($init === null) {
                    continue;
                }

                foreach ($init->classDefinitions as $binding) {
                    foreach ($binding->abstracts as $abstract) {
                        $map[$abstract] = $binding;
                    }
                }
            }

            foreach ($app->postBootstrapBindings as $binding) {
                foreach ($binding->abstracts as $abstract) {
                    $map[$abstract] = $binding;
                }
            }
        }

        return $map;
    }

    /**
     * Group edges by source FQCN to build the top-down dependency map.
     *
     * @param list<array{source: string, type: string, target: string, via: ?string}> $rawEdges
     * @return array<string, GraphNode>
     */
    protected function buildDependencyMap(array $rawEdges, ProjectIndex $index): array
    {
        $grouped = [];

        foreach ($rawEdges as $edge) {
            if ($edge['source'] === $edge['target']) {
                continue;
            }

            $key = $edge['type'] . ':' . $edge['target'];

            if (!isset($grouped[$edge['source']][$key])) {
                $grouped[$edge['source']][$key] = $edge;
            }
        }

        $nodes = [];

        foreach ($grouped as $fqcn => $edges) {
            $file = isset($index->classes[$fqcn]) ? $index->classes[$fqcn]->file : null;

            $graphEdges = array_map(
                fn(array $e) => new GraphEdge($e['type'], $e['target'], $e['via']),
                array_values($edges)
            );

            $nodes[$fqcn] = new GraphNode($fqcn, $file, $graphEdges);
        }

        return $nodes;
    }

    /**
     * Invert edges and group by target FQCN to build the bottom-up dependents map.
     *
     * @param list<array{source: string, type: string, target: string, via: ?string}> $rawEdges
     * @return array<string, GraphNode>
     */
    protected function buildDependentsMap(array $rawEdges, ProjectIndex $index): array
    {
        $grouped = [];

        foreach ($rawEdges as $edge) {
            if ($edge['source'] === $edge['target']) {
                continue;
            }

            $invertedType = self::INVERTED_TYPES[$edge['type']] ?? $edge['type'];
            $key = $invertedType . ':' . $edge['source'];

            if (!isset($grouped[$edge['target']][$key])) {
                $grouped[$edge['target']][$key] = [
                    'type' => $invertedType,
                    'fqcn' => $edge['source'],
                    'via' => $edge['via'],
                ];
            }
        }

        $nodes = [];

        foreach ($grouped as $fqcn => $edges) {
            $file = isset($index->classes[$fqcn]) ? $index->classes[$fqcn]->file : null;

            $graphEdges = array_map(
                fn(array $e) => new GraphEdge($e['type'], $e['fqcn'], $e['via']),
                array_values($edges)
            );

            $nodes[$fqcn] = new GraphNode($fqcn, $file, $graphEdges);
        }

        return $nodes;
    }

    /**
     * Find classes with no edges in either direction.
     *
     * @param array<string, GraphNode> $dependencyMap
     * @param array<string, GraphNode> $dependentsMap
     * @return OrphanEntry[]
     */
    protected function findOrphans(ProjectIndex $index, array $dependencyMap, array $dependentsMap): array
    {
        $orphans = [];

        foreach ($index->classes as $fqcn => $class) {
            if (!isset($dependencyMap[$fqcn]) && !isset($dependentsMap[$fqcn])) {
                $orphans[] = new OrphanEntry($fqcn, $class->file);
            }
        }

        return $orphans;
    }
}
