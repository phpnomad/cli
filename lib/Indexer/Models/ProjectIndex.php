<?php

namespace PHPNomad\Cli\Indexer\Models;

final class ProjectIndex
{
    /**
     * @param string $projectPath
     * @param string $indexedAt ISO 8601 timestamp
     * @param array<string, IndexedClass> $classes Keyed by FQCN
     * @param IndexedApplication[] $applications
     * @param array<string, IndexedInitializer> $initializers Keyed by FQCN
     * @param array<string, ResolvedController> $resolvedControllers Keyed by FQCN
     * @param array<string, ResolvedCommand> $resolvedCommands Keyed by FQCN
     * @param array<string, DependencyNode> $dependencyTrees Keyed by abstract FQCN
     * @param array<string, ResolvedTable> $resolvedTables Keyed by FQCN
     * @param array<string, ResolvedEvent> $resolvedEvents Keyed by FQCN
     * @param array<string, ResolvedGraphQLType> $resolvedGraphQLTypes Keyed by FQCN
     * @param array<string, ResolvedFacade> $resolvedFacades Keyed by FQCN
     * @param ResolvedTaskHandler[] $resolvedTaskHandlers
     * @param array<string, ResolvedMutation> $resolvedMutations Keyed by FQCN
     */
    public function __construct(
        public readonly string $projectPath,
        public readonly string $indexedAt,
        public readonly array $classes,
        public readonly array $applications,
        public readonly array $initializers,
        public readonly array $resolvedControllers = [],
        public readonly array $resolvedCommands = [],
        public readonly array $dependencyTrees = [],
        public readonly array $resolvedTables = [],
        public readonly array $resolvedEvents = [],
        public readonly array $resolvedGraphQLTypes = [],
        public readonly array $resolvedFacades = [],
        public readonly array $resolvedTaskHandlers = [],
        public readonly array $resolvedMutations = []
    ) {
    }

    public function getTotalBindings(): int
    {
        $count = 0;

        foreach ($this->applications as $app) {
            $count += count($app->preBootstrapBindings);
            $count += count($app->postBootstrapBindings);
        }

        foreach ($this->initializers as $init) {
            $count += count($init->classDefinitions);
        }

        return $count;
    }

    public function getTotalControllers(): int
    {
        $count = 0;

        foreach ($this->initializers as $init) {
            $count += count($init->controllers);
        }

        return $count;
    }

    public function getTotalListeners(): int
    {
        $count = 0;

        foreach ($this->initializers as $init) {
            $count += array_sum(array_map('count', $init->listeners));
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return [
            'projectPath' => $this->projectPath,
            'indexedAt' => $this->indexedAt,
            'counts' => [
                'classes' => count($this->classes),
                'applications' => count($this->applications),
                'initializers' => count($this->initializers),
                'bindings' => $this->getTotalBindings(),
                'controllers' => $this->getTotalControllers(),
                'listeners' => $this->getTotalListeners(),
                'resolvedControllers' => count($this->resolvedControllers),
                'resolvedCommands' => count($this->resolvedCommands),
                'dependencyTrees' => count($this->dependencyTrees),
                'resolvedTables' => count($this->resolvedTables),
                'resolvedEvents' => count($this->resolvedEvents),
                'resolvedGraphQLTypes' => count($this->resolvedGraphQLTypes),
                'resolvedFacades' => count($this->resolvedFacades),
                'resolvedTaskHandlers' => count($this->resolvedTaskHandlers),
                'resolvedMutations' => count($this->resolvedMutations),
            ],
        ];
    }
}
