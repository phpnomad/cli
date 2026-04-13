<?php

namespace PHPNomad\Cli\Indexer\Models;

final class DependencyNode
{
    /**
     * @param string $abstract The interface or type being resolved
     * @param ?string $concrete The concrete class, null if unresolved
     * @param ?string $source Initializer or Application that registered the binding
     * @param string $resolutionType "declarative"|"imperative"|"auto-wired"|"unresolved"|"circular"|"builtin"
     * @param DependencyNode[] $dependencies Recursive child dependencies
     */
    public function __construct(
        public readonly string $abstract,
        public readonly ?string $concrete,
        public readonly ?string $source,
        public readonly string $resolutionType,
        public readonly array $dependencies
    ) {
    }
}
