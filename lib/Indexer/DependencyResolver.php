<?php

namespace PHPNomad\Cli\Indexer;

use PHPNomad\Cli\Indexer\Models\DependencyNode;
use PHPNomad\Cli\Indexer\Models\IndexedBinding;
use PHPNomad\Cli\Indexer\Models\ProjectIndex;

class DependencyResolver
{
    protected const MAX_DEPTH = 10;

    public function __construct(
        protected ClassIndex $classIndex
    ) {
    }

    /**
     * Build dependency trees for all bindings in the index.
     *
     * @return array<string, DependencyNode> Keyed by abstract FQCN
     */
    public function resolve(ProjectIndex $index, string $basePath): array
    {
        $bindingMap = $this->buildBindingMap($index);
        $trees = [];

        foreach ($bindingMap as $abstract => $binding) {
            $trees[$abstract] = $this->resolveNode($abstract, $bindingMap, $index, $basePath, []);
        }

        return $trees;
    }

    /**
     * Build a merged binding map from all sources in boot order.
     *
     * @return array<string, IndexedBinding> Keyed by abstract FQCN
     */
    protected function buildBindingMap(ProjectIndex $index): array
    {
        $map = [];

        foreach ($index->applications as $app) {
            // Pre-bootstrap bindings
            foreach ($app->preBootstrapBindings as $binding) {
                foreach ($binding->abstracts as $abstract) {
                    $map[$abstract] = $binding;
                }
            }

            // Declarative bindings from initializers in boot sequence order
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

            // Post-bootstrap bindings
            foreach ($app->postBootstrapBindings as $binding) {
                foreach ($binding->abstracts as $abstract) {
                    $map[$abstract] = $binding;
                }
            }
        }

        return $map;
    }

    /**
     * Recursively resolve a dependency node.
     *
     * @param array<string, IndexedBinding> $bindingMap
     * @param array<string, bool> $visited Cycle detection per branch
     */
    protected function resolveNode(
        string $abstract,
        array $bindingMap,
        ProjectIndex $index,
        string $basePath,
        array $visited,
        int $depth = 0
    ): DependencyNode {
        // Cycle detection
        if (isset($visited[$abstract])) {
            return new DependencyNode($abstract, null, null, 'circular', []);
        }

        // Depth cap
        if ($depth >= self::MAX_DEPTH) {
            return new DependencyNode($abstract, null, null, 'unresolved', []);
        }

        $visited[$abstract] = true;

        $binding = $bindingMap[$abstract] ?? null;

        if ($binding === null) {
            // No binding — try auto-wiring (the abstract IS the concrete)
            $class = $index->classes[$abstract] ?? null;

            if ($class === null) {
                $class = $this->classIndex->resolveFromVendor($abstract, $basePath);
            }

            if ($class === null) {
                return new DependencyNode($abstract, null, null, 'unresolved', []);
            }

            $children = $this->resolveConstructorDeps($class->constructorParams, $bindingMap, $index, $basePath, $visited, $depth);

            return new DependencyNode($abstract, $abstract, null, 'auto-wired', $children);
        }

        $concrete = $binding->concrete;
        $source = $binding->source;
        $resolutionType = $binding->bindingType;

        // Look up the concrete class to walk its constructor params
        $class = $index->classes[$concrete] ?? null;

        if ($class === null) {
            $class = $this->classIndex->resolveFromVendor($concrete, $basePath);
        }

        if ($class === null) {
            // Concrete is bound but we can't find the class (likely a factory binding)
            return new DependencyNode($abstract, $concrete, $source, $resolutionType, []);
        }

        $children = $this->resolveConstructorDeps($class->constructorParams, $bindingMap, $index, $basePath, $visited, $depth);

        return new DependencyNode($abstract, $concrete, $source, $resolutionType, $children);
    }

    /**
     * Resolve constructor parameter dependencies.
     *
     * @param \PHPNomad\Cli\Indexer\Models\ConstructorParam[] $params
     * @param array<string, IndexedBinding> $bindingMap
     * @param array<string, bool> $visited
     * @return DependencyNode[]
     */
    protected function resolveConstructorDeps(
        array $params,
        array $bindingMap,
        ProjectIndex $index,
        string $basePath,
        array $visited,
        int $depth
    ): array {
        $children = [];

        foreach ($params as $param) {
            if ($param->isBuiltin || $param->type === null) {
                continue;
            }

            $children[] = $this->resolveNode(
                $param->type,
                $bindingMap,
                $index,
                $basePath,
                $visited,
                $depth + 1
            );
        }

        return $children;
    }
}
