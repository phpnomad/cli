<?php

namespace PHPNomad\Cli\Indexer;

use PHPNomad\Cli\Indexer\Adapters\DependencyNodeAdapter;
use PHPNomad\Cli\Indexer\Adapters\IndexedApplicationAdapter;
use PHPNomad\Cli\Indexer\Adapters\IndexedClassAdapter;
use PHPNomad\Cli\Indexer\Adapters\IndexedInitializerAdapter;
use PHPNomad\Cli\Indexer\Adapters\ResolvedCommandAdapter;
use PHPNomad\Cli\Indexer\Adapters\ResolvedControllerAdapter;
use PHPNomad\Cli\Indexer\Adapters\ResolvedEventAdapter;
use PHPNomad\Cli\Indexer\Adapters\ResolvedFacadeAdapter;
use PHPNomad\Cli\Indexer\Adapters\ResolvedGraphQLTypeAdapter;
use PHPNomad\Cli\Indexer\Adapters\ResolvedMutationAdapter;
use PHPNomad\Cli\Indexer\Adapters\ResolvedTableAdapter;
use PHPNomad\Cli\Indexer\Adapters\ResolvedTaskHandlerAdapter;
use PHPNomad\Cli\Indexer\Models\ProjectIndex;
use PHPNomad\Console\Interfaces\OutputStrategy;

class ProjectIndexer
{
    public function __construct(
        protected ClassIndex $classIndex,
        protected BootSequenceWalker $bootSequenceWalker,
        protected InitializerAnalyzer $initializerAnalyzer,
        protected ControllerAnalyzer $controllerAnalyzer,
        protected CommandAnalyzer $commandAnalyzer,
        protected DependencyResolver $dependencyResolver,
        protected TableAnalyzer $tableAnalyzer,
        protected EventAnalyzer $eventAnalyzer,
        protected GraphQLTypeAnalyzer $graphQLTypeAnalyzer,
        protected FacadeAnalyzer $facadeAnalyzer,
        protected TaskHandlerAnalyzer $taskHandlerAnalyzer,
        protected MutationAnalyzer $mutationAnalyzer,
        protected IndexedClassAdapter $classAdapter,
        protected IndexedInitializerAdapter $initializerAdapter,
        protected IndexedApplicationAdapter $applicationAdapter,
        protected ResolvedControllerAdapter $controllerAdapter,
        protected ResolvedCommandAdapter $commandAdapter,
        protected DependencyNodeAdapter $dependencyNodeAdapter,
        protected ResolvedTableAdapter $tableAdapter,
        protected ResolvedEventAdapter $eventAdapter,
        protected ResolvedGraphQLTypeAdapter $graphQLTypeAdapter,
        protected ResolvedFacadeAdapter $facadeAdapter,
        protected ResolvedTaskHandlerAdapter $taskHandlerAdapter,
        protected ResolvedMutationAdapter $mutationAdapter
    ) {
    }

    /**
     * Run the full indexing pipeline against a project directory.
     */
    public function index(string $path, OutputStrategy $output): ProjectIndex
    {
        $path = rtrim($path, '/');

        $output->info('Scanning project files...');
        $classes = $this->classIndex->build($path);
        $output->writeln('  Found ' . count($classes) . ' classes');

        $output->info('Walking boot sequences...');
        $applications = $this->bootSequenceWalker->walk($path, $classes);
        $output->writeln('  Found ' . count($applications) . ' application(s)');

        // Collect all Initializer FQCNs referenced in boot sequences
        $initializerFqcns = [];

        foreach ($applications as $app) {
            foreach ($app->getAllInitializerReferences() as $ref) {
                if ($ref->fqcn !== null && !$ref->isDynamic) {
                    $initializerFqcns[$ref->fqcn] = true;
                }
            }
        }

        $output->info('Analyzing ' . count($initializerFqcns) . ' initializers...');
        $initializers = [];

        foreach ($initializerFqcns as $fqcn => $_) {
            $class = $classes[$fqcn] ?? null;

            if ($class === null) {
                $class = $this->classIndex->resolveFromVendor($fqcn, $path);
            }

            if ($class === null) {
                $output->warning("  Could not resolve: $fqcn");
                continue;
            }

            $initializer = $this->initializerAnalyzer->analyze($class, $path);
            $initializers[$fqcn] = $initializer;
        }

        $output->writeln('  Analyzed ' . count($initializers) . ' initializers');

        // Resolve controllers
        $controllerFqcns = [];
        foreach ($initializers as $init) {
            foreach ($init->controllers as $fqcn) {
                $controllerFqcns[$fqcn] = true;
            }
        }

        $output->info('Resolving ' . count($controllerFqcns) . ' controllers...');
        $resolvedControllers = [];

        foreach ($controllerFqcns as $fqcn => $_) {
            $class = $classes[$fqcn] ?? null;

            if ($class === null) {
                $class = $this->classIndex->resolveFromVendor($fqcn, $path);
            }

            if ($class === null) {
                $output->warning("  Could not resolve controller: $fqcn");
                continue;
            }

            $resolvedControllers[$fqcn] = $this->controllerAnalyzer->analyze($class, $path);
        }

        $output->writeln('  Resolved ' . count($resolvedControllers) . ' controllers');

        // Resolve commands
        $commandFqcns = [];
        foreach ($initializers as $init) {
            foreach ($init->commands as $fqcn) {
                $commandFqcns[$fqcn] = true;
            }
        }

        $output->info('Resolving ' . count($commandFqcns) . ' commands...');
        $resolvedCommands = [];

        foreach ($commandFqcns as $fqcn => $_) {
            $class = $classes[$fqcn] ?? null;

            if ($class === null) {
                $class = $this->classIndex->resolveFromVendor($fqcn, $path);
            }

            if ($class === null) {
                $output->warning("  Could not resolve command: $fqcn");
                continue;
            }

            $resolvedCommands[$fqcn] = $this->commandAnalyzer->analyze($class, $path);
        }

        $output->writeln('  Resolved ' . count($resolvedCommands) . ' commands');

        // Resolve tables
        $resolvedTables = [];
        foreach ($classes as $class) {
            if ($this->tableAnalyzer->isTable($class)) {
                $resolvedTables[$class->fqcn] = $this->tableAnalyzer->analyze($class, $path);
            }
        }

        $output->info('Resolved ' . count($resolvedTables) . ' tables');

        // Resolve events
        $resolvedEvents = [];
        foreach ($classes as $class) {
            if ($this->eventAnalyzer->isEvent($class)) {
                $resolvedEvents[$class->fqcn] = $this->eventAnalyzer->analyze($class, $path);
            }
        }

        $output->info('Resolved ' . count($resolvedEvents) . ' events');

        // Resolve GraphQL types
        $graphQLTypeFqcns = [];
        foreach ($initializers as $init) {
            foreach ($init->typeDefinitions as $fqcn) {
                $graphQLTypeFqcns[$fqcn] = true;
            }
        }

        $resolvedGraphQLTypes = [];
        foreach ($graphQLTypeFqcns as $fqcn => $_) {
            $class = $classes[$fqcn] ?? null;

            if ($class === null) {
                $class = $this->classIndex->resolveFromVendor($fqcn, $path);
            }

            if ($class !== null) {
                $resolvedGraphQLTypes[$fqcn] = $this->graphQLTypeAnalyzer->analyze($class, $path);
            }
        }

        if (!empty($resolvedGraphQLTypes)) {
            $output->info('Resolved ' . count($resolvedGraphQLTypes) . ' GraphQL types');
        }

        // Resolve facades
        $facadeFqcns = [];
        foreach ($initializers as $init) {
            foreach ($init->facades as $fqcn) {
                $facadeFqcns[$fqcn] = true;
            }
        }

        $resolvedFacades = [];
        foreach ($facadeFqcns as $fqcn => $_) {
            $class = $classes[$fqcn] ?? null;

            if ($class === null) {
                $class = $this->classIndex->resolveFromVendor($fqcn, $path);
            }

            if ($class !== null) {
                $resolvedFacades[$fqcn] = $this->facadeAnalyzer->analyze($class, $path);
            }
        }

        if (!empty($resolvedFacades)) {
            $output->info('Resolved ' . count($resolvedFacades) . ' facades');
        }

        // Resolve task handlers
        $resolvedTaskHandlers = [];
        foreach ($initializers as $init) {
            foreach ($init->taskHandlers as $taskClassFqcn => $handlerFqcns) {
                foreach ($handlerFqcns as $handlerFqcn) {
                    $handlerClass = $classes[$handlerFqcn] ?? null;

                    if ($handlerClass === null) {
                        $handlerClass = $this->classIndex->resolveFromVendor($handlerFqcn, $path);
                    }

                    if ($handlerClass === null) {
                        continue;
                    }

                    $taskClass = $classes[$taskClassFqcn] ?? null;

                    if ($taskClass === null) {
                        $taskClass = $this->classIndex->resolveFromVendor($taskClassFqcn, $path);
                    }

                    $resolvedTaskHandlers[] = $this->taskHandlerAnalyzer->analyze(
                        $handlerClass,
                        $taskClassFqcn,
                        $taskClass,
                        $path
                    );
                }
            }
        }

        if (!empty($resolvedTaskHandlers)) {
            $output->info('Resolved ' . count($resolvedTaskHandlers) . ' task handlers');
        }

        // Resolve mutations
        $mutationMap = [];
        foreach ($initializers as $init) {
            foreach ($init->mutations as $mutatorFqcn => $actions) {
                if (!isset($mutationMap[$mutatorFqcn])) {
                    $mutationMap[$mutatorFqcn] = [];
                }
                foreach ($actions as $action) {
                    if (!in_array($action, $mutationMap[$mutatorFqcn], true)) {
                        $mutationMap[$mutatorFqcn][] = $action;
                    }
                }
            }
        }

        $resolvedMutations = [];
        foreach ($mutationMap as $fqcn => $actions) {
            $class = $classes[$fqcn] ?? null;

            if ($class === null) {
                $class = $this->classIndex->resolveFromVendor($fqcn, $path);
            }

            if ($class !== null) {
                $resolvedMutations[$fqcn] = $this->mutationAnalyzer->analyze($class, $actions, $path);
            }
        }

        if (!empty($resolvedMutations)) {
            $output->info('Resolved ' . count($resolvedMutations) . ' mutations');
        }

        // Build the index first (without dependency trees) so the resolver can use it
        $index = new ProjectIndex(
            $path,
            date('c'),
            $classes,
            $applications,
            $initializers,
            $resolvedControllers,
            $resolvedCommands
        );

        // Resolve dependency trees
        $output->info('Resolving dependency trees...');
        $dependencyTrees = $this->dependencyResolver->resolve($index, $path);
        $output->writeln('  Resolved ' . count($dependencyTrees) . ' dependency trees');

        return new ProjectIndex(
            $path,
            date('c'),
            $classes,
            $applications,
            $initializers,
            $resolvedControllers,
            $resolvedCommands,
            $dependencyTrees,
            $resolvedTables,
            $resolvedEvents,
            $resolvedGraphQLTypes,
            $resolvedFacades,
            $resolvedTaskHandlers,
            $resolvedMutations
        );
    }

    /**
     * Save the index to disk as JSONL files.
     */
    public function save(ProjectIndex $index, string $path): string
    {
        $dir = rtrim($path, '/') . '/.phpnomad';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir . '/meta.json', json_encode($index->getMeta(), JSON_UNESCAPED_SLASHES));

        $this->writeJsonlFile($dir . '/classes.jsonl', $index->classes, fn($c) => $this->classAdapter->toArray($c));
        $this->writeJsonlFile($dir . '/initializers.jsonl', $index->initializers, fn($i) => $this->initializerAdapter->toArray($i));
        $this->writeJsonlFile($dir . '/applications.jsonl', $index->applications, fn($a) => $this->applicationAdapter->toArray($a));
        $this->writeJsonlFile($dir . '/controllers.jsonl', $index->resolvedControllers, fn($c) => $this->controllerAdapter->toArray($c));
        $this->writeJsonlFile($dir . '/commands.jsonl', $index->resolvedCommands, fn($c) => $this->commandAdapter->toArray($c));
        $this->writeJsonlFile($dir . '/dependencies.jsonl', $index->dependencyTrees, fn($d) => $this->dependencyNodeAdapter->toArray($d));
        $this->writeJsonlFile($dir . '/tables.jsonl', $index->resolvedTables, fn($t) => $this->tableAdapter->toArray($t));
        $this->writeJsonlFile($dir . '/events.jsonl', $index->resolvedEvents, fn($e) => $this->eventAdapter->toArray($e));
        $this->writeJsonlFile($dir . '/graphql-types.jsonl', $index->resolvedGraphQLTypes, fn($t) => $this->graphQLTypeAdapter->toArray($t));
        $this->writeJsonlFile($dir . '/facades.jsonl', $index->resolvedFacades, fn($f) => $this->facadeAdapter->toArray($f));
        $this->writeJsonlFile($dir . '/task-handlers.jsonl', $index->resolvedTaskHandlers, fn($h) => $this->taskHandlerAdapter->toArray($h));
        $this->writeJsonlFile($dir . '/mutations.jsonl', $index->resolvedMutations, fn($m) => $this->mutationAdapter->toArray($m));

        return $dir;
    }

    /**
     * Load a previously saved index from disk.
     */
    public function load(string $path): ?ProjectIndex
    {
        $dir = rtrim($path, '/') . '/.phpnomad';
        $metaFile = $dir . '/meta.json';

        if (!file_exists($metaFile)) {
            return null;
        }

        $meta = json_decode(file_get_contents($metaFile), true);

        $classes = $this->readJsonlFile($dir . '/classes.jsonl', fn($d) => $this->classAdapter->fromArray($d), 'fqcn');
        $initializers = $this->readJsonlFile($dir . '/initializers.jsonl', fn($d) => $this->initializerAdapter->fromArray($d), 'fqcn');
        $applications = $this->readJsonlFile($dir . '/applications.jsonl', fn($d) => $this->applicationAdapter->fromArray($d));
        $resolvedControllers = $this->readJsonlFile($dir . '/controllers.jsonl', fn($d) => $this->controllerAdapter->fromArray($d), 'fqcn');
        $resolvedCommands = $this->readJsonlFile($dir . '/commands.jsonl', fn($d) => $this->commandAdapter->fromArray($d), 'fqcn');
        $dependencyTrees = $this->readJsonlFile($dir . '/dependencies.jsonl', fn($d) => $this->dependencyNodeAdapter->fromArray($d), 'abstract');
        $resolvedTables = $this->readJsonlFile($dir . '/tables.jsonl', fn($d) => $this->tableAdapter->fromArray($d), 'fqcn');
        $resolvedEvents = $this->readJsonlFile($dir . '/events.jsonl', fn($d) => $this->eventAdapter->fromArray($d), 'fqcn');
        $resolvedGraphQLTypes = $this->readJsonlFile($dir . '/graphql-types.jsonl', fn($d) => $this->graphQLTypeAdapter->fromArray($d), 'fqcn');
        $resolvedFacades = $this->readJsonlFile($dir . '/facades.jsonl', fn($d) => $this->facadeAdapter->fromArray($d), 'fqcn');
        $resolvedTaskHandlers = $this->readJsonlFile($dir . '/task-handlers.jsonl', fn($d) => $this->taskHandlerAdapter->fromArray($d));
        $resolvedMutations = $this->readJsonlFile($dir . '/mutations.jsonl', fn($d) => $this->mutationAdapter->fromArray($d), 'fqcn');

        return new ProjectIndex(
            $meta['projectPath'] ?? '',
            $meta['indexedAt'] ?? '',
            $classes,
            $applications,
            $initializers,
            $resolvedControllers,
            $resolvedCommands,
            $dependencyTrees,
            $resolvedTables,
            $resolvedEvents,
            $resolvedGraphQLTypes,
            $resolvedFacades,
            $resolvedTaskHandlers,
            $resolvedMutations
        );
    }

    /**
     * Serialize the full index to JSON for command output.
     */
    public function toJson(ProjectIndex $index): string
    {
        return json_encode($this->toArray($index), JSON_UNESCAPED_SLASHES);
    }

    /**
     * Convert the full index to a nested array.
     */
    public function toArray(ProjectIndex $index): array
    {
        $classes = [];
        foreach ($index->classes as $fqcn => $class) {
            $classes[$fqcn] = $this->classAdapter->toArray($class);
        }

        $initializers = [];
        foreach ($index->initializers as $fqcn => $init) {
            $initializers[$fqcn] = $this->initializerAdapter->toArray($init);
        }

        $controllers = [];
        foreach ($index->resolvedControllers as $fqcn => $ctrl) {
            $controllers[$fqcn] = $this->controllerAdapter->toArray($ctrl);
        }

        $commands = [];
        foreach ($index->resolvedCommands as $fqcn => $cmd) {
            $commands[$fqcn] = $this->commandAdapter->toArray($cmd);
        }

        $dependencies = [];
        foreach ($index->dependencyTrees as $abstract => $tree) {
            $dependencies[$abstract] = $this->dependencyNodeAdapter->toArray($tree);
        }

        $tables = [];
        foreach ($index->resolvedTables as $fqcn => $table) {
            $tables[$fqcn] = $this->tableAdapter->toArray($table);
        }

        $events = [];
        foreach ($index->resolvedEvents as $fqcn => $event) {
            $events[$fqcn] = $this->eventAdapter->toArray($event);
        }

        $graphQLTypes = [];
        foreach ($index->resolvedGraphQLTypes as $fqcn => $type) {
            $graphQLTypes[$fqcn] = $this->graphQLTypeAdapter->toArray($type);
        }

        $facades = [];
        foreach ($index->resolvedFacades as $fqcn => $facade) {
            $facades[$fqcn] = $this->facadeAdapter->toArray($facade);
        }

        $mutations = [];
        foreach ($index->resolvedMutations as $fqcn => $mutation) {
            $mutations[$fqcn] = $this->mutationAdapter->toArray($mutation);
        }

        return [
            'projectPath' => $index->projectPath,
            'indexedAt' => $index->indexedAt,
            'classes' => $classes,
            'applications' => array_map(fn($a) => $this->applicationAdapter->toArray($a), $index->applications),
            'initializers' => $initializers,
            'resolvedControllers' => $controllers,
            'resolvedCommands' => $commands,
            'dependencyTrees' => $dependencies,
            'resolvedTables' => $tables,
            'resolvedEvents' => $events,
            'resolvedGraphQLTypes' => $graphQLTypes,
            'resolvedFacades' => $facades,
            'resolvedTaskHandlers' => array_map(fn($h) => $this->taskHandlerAdapter->toArray($h), $index->resolvedTaskHandlers),
            'resolvedMutations' => $mutations,
        ];
    }

    /**
     * Write an array of items to a JSONL file.
     */
    protected function writeJsonlFile(string $filePath, array $items, callable $toArray): void
    {
        $fh = fopen($filePath, 'w');

        foreach ($items as $item) {
            fwrite($fh, json_encode($toArray($item), JSON_UNESCAPED_SLASHES) . "\n");
        }

        fclose($fh);
    }

    /**
     * Read items from a JSONL file.
     *
     * @param ?string $keyField If provided, the resulting array is keyed by this field from the raw data
     */
    protected function readJsonlFile(string $filePath, callable $fromArray, ?string $keyField = null): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $items = [];

        foreach (file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $data = json_decode($line, true);

            if ($data === null) {
                continue;
            }

            $item = $fromArray($data);

            if ($keyField !== null && isset($data[$keyField])) {
                $items[$data[$keyField]] = $item;
            } else {
                $items[] = $item;
            }
        }

        return $items;
    }
}
