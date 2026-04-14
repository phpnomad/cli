<?php

namespace PHPNomad\Cli\Tests\Indexer;

use PHPNomad\Cli\Indexer\DependencyGraphBuilder;
use PHPNomad\Cli\Indexer\Models\BootstrapperCall;
use PHPNomad\Cli\Indexer\Models\ConstructorParam;
use PHPNomad\Cli\Indexer\Models\IndexedApplication;
use PHPNomad\Cli\Indexer\Models\IndexedBinding;
use PHPNomad\Cli\Indexer\Models\IndexedClass;
use PHPNomad\Cli\Indexer\Models\IndexedInitializer;
use PHPNomad\Cli\Indexer\Models\InitializerReference;
use PHPNomad\Cli\Indexer\Models\ProjectIndex;
use PHPNomad\Cli\Indexer\Models\ResolvedFacade;
use PHPNomad\Cli\Indexer\Models\ResolvedMutation;
use PHPNomad\Cli\Indexer\Models\ResolvedTaskHandler;
use PHPUnit\Framework\TestCase;

class DependencyGraphBuilderTest extends TestCase
{
    private DependencyGraphBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new DependencyGraphBuilder();
    }

    public function testInjectsEdges(): void
    {
        $index = $this->buildIndex(classes: [
            'App\\Service' => $this->makeClass('App\\Service', 'lib/Service.php', constructorParams: [
                new ConstructorParam('datastore', 'App\\DatastoreInterface', false),
                new ConstructorParam('name', 'string', true),
            ]),
            'App\\DatastoreInterface' => $this->makeClass('App\\DatastoreInterface', 'lib/DatastoreInterface.php'),
        ]);

        $result = $this->builder->build($index);

        $this->assertArrayHasKey('App\\Service', $result['dependencyMap']);
        $edges = $result['dependencyMap']['App\\Service']->edges;
        $this->assertEdgeExists($edges, 'injects', 'App\\DatastoreInterface');

        $this->assertArrayHasKey('App\\DatastoreInterface', $result['dependentsMap']);
        $edges = $result['dependentsMap']['App\\DatastoreInterface']->edges;
        $this->assertEdgeExists($edges, 'injected-by', 'App\\Service');
    }

    public function testImplementsEdges(): void
    {
        $index = $this->buildIndex(classes: [
            'App\\MySqlDatastore' => $this->makeClass('App\\MySqlDatastore', 'lib/MySqlDatastore.php', implements: ['App\\DatastoreInterface']),
        ]);

        $result = $this->builder->build($index);

        $this->assertArrayHasKey('App\\MySqlDatastore', $result['dependencyMap']);
        $this->assertEdgeExists($result['dependencyMap']['App\\MySqlDatastore']->edges, 'implements', 'App\\DatastoreInterface');

        $this->assertArrayHasKey('App\\DatastoreInterface', $result['dependentsMap']);
        $this->assertEdgeExists($result['dependentsMap']['App\\DatastoreInterface']->edges, 'implemented-by', 'App\\MySqlDatastore');
    }

    public function testExtendsEdges(): void
    {
        $index = $this->buildIndex(classes: [
            'App\\Child' => $this->makeClass('App\\Child', 'lib/Child.php', parentClass: 'App\\Parent'),
        ]);

        $result = $this->builder->build($index);

        $this->assertEdgeExists($result['dependencyMap']['App\\Child']->edges, 'extends', 'App\\Parent');
        $this->assertEdgeExists($result['dependentsMap']['App\\Parent']->edges, 'extended-by', 'App\\Child');
    }

    public function testUsesTraitEdges(): void
    {
        $index = $this->buildIndex(classes: [
            'App\\Service' => $this->makeClass('App\\Service', 'lib/Service.php', traits: ['App\\Loggable']),
        ]);

        $result = $this->builder->build($index);

        $this->assertEdgeExists($result['dependencyMap']['App\\Service']->edges, 'uses-trait', 'App\\Loggable');
        $this->assertEdgeExists($result['dependentsMap']['App\\Loggable']->edges, 'trait-used-by', 'App\\Service');
    }

    public function testListensToEdges(): void
    {
        $index = $this->buildIndex(
            classes: [
                'App\\Listener' => $this->makeClass('App\\Listener', 'lib/Listener.php'),
            ],
            initializers: [
                'App\\Init' => $this->makeInitializer('App\\Init', 'lib/Init.php', listeners: [
                    'App\\SaleEvent' => ['App\\Listener'],
                ]),
            ]
        );

        $result = $this->builder->build($index);

        $this->assertEdgeExists($result['dependencyMap']['App\\Listener']->edges, 'listens-to', 'App\\SaleEvent');
        $this->assertEdgeExists($result['dependentsMap']['App\\SaleEvent']->edges, 'listened-by', 'App\\Listener');
    }

    public function testHandlesTaskEdges(): void
    {
        $index = $this->buildIndex(
            classes: [
                'App\\Handler' => $this->makeClass('App\\Handler', 'lib/Handler.php'),
            ],
            resolvedTaskHandlers: [
                new ResolvedTaskHandler('App\\Handler', 'lib/Handler.php', 'App\\SendEmailTask', 'send_email', 'lib/SendEmailTask.php'),
            ]
        );

        $result = $this->builder->build($index);

        $this->assertEdgeExists($result['dependencyMap']['App\\Handler']->edges, 'handles-task', 'App\\SendEmailTask');
        $this->assertEdgeExists($result['dependentsMap']['App\\SendEmailTask']->edges, 'task-handled-by', 'App\\Handler');
    }

    public function testProxiesEdges(): void
    {
        $index = $this->buildIndex(
            classes: [
                'App\\PayoutFacade' => $this->makeClass('App\\PayoutFacade', 'lib/PayoutFacade.php'),
            ],
            resolvedFacades: [
                'App\\PayoutFacade' => new ResolvedFacade('App\\PayoutFacade', 'lib/PayoutFacade.php', 'App\\PayoutInterface'),
            ]
        );

        $result = $this->builder->build($index);

        $this->assertEdgeExists($result['dependencyMap']['App\\PayoutFacade']->edges, 'proxies', 'App\\PayoutInterface');
        $this->assertEdgeExists($result['dependentsMap']['App\\PayoutInterface']->edges, 'proxied-by', 'App\\PayoutFacade');
    }

    public function testMutatesViaEdges(): void
    {
        $index = $this->buildIndex(
            classes: [
                'App\\UserMutator' => $this->makeClass('App\\UserMutator', 'lib/UserMutator.php'),
            ],
            resolvedMutations: [
                'App\\UserMutator' => new ResolvedMutation('App\\UserMutator', 'lib/UserMutator.php', ['create', 'update'], true, 'App\\UserAdapter'),
            ]
        );

        $result = $this->builder->build($index);

        $this->assertEdgeExists($result['dependencyMap']['App\\UserMutator']->edges, 'mutates-via', 'App\\UserAdapter');
        $this->assertEdgeExists($result['dependentsMap']['App\\UserAdapter']->edges, 'adapter-for', 'App\\UserMutator');
    }

    public function testResolvesToEdges(): void
    {
        $binding = new IndexedBinding('App\\MySqlDatastore', ['App\\DatastoreInterface'], 'App\\MySqlInit', 'lib/MySqlInit.php', 'declarative');

        $index = $this->buildIndex(
            classes: [
                'App\\MySqlDatastore' => $this->makeClass('App\\MySqlDatastore', 'lib/MySqlDatastore.php'),
            ],
            initializers: [
                'App\\MySqlInit' => $this->makeInitializer('App\\MySqlInit', 'lib/MySqlInit.php', classDefinitions: [$binding]),
            ],
            applications: [
                new IndexedApplication('App\\App', 'lib/App.php', [], [
                    new BootstrapperCall('boot', 1, [new InitializerReference('App\\MySqlInit', 'inline', false)]),
                ], []),
            ]
        );

        $result = $this->builder->build($index);

        $this->assertArrayHasKey('App\\DatastoreInterface', $result['dependencyMap']);
        $depEdges = $result['dependencyMap']['App\\DatastoreInterface']->edges;
        $this->assertEdgeExists($depEdges, 'resolves-to', 'App\\MySqlDatastore');

        // Check that resolves-to edge has via field
        $resolvesEdge = $this->findEdge($depEdges, 'resolves-to', 'App\\MySqlDatastore');
        $this->assertNotNull($resolvesEdge);
        $this->assertSame('App\\MySqlInit', $resolvesEdge->via);

        // Check inverted
        $this->assertEdgeExists($result['dependentsMap']['App\\MySqlDatastore']->edges, 'resolved-from', 'App\\DatastoreInterface');
    }

    public function testSelfReferentialEdgesAreFiltered(): void
    {
        $index = $this->buildIndex(classes: [
            'App\\SelfRef' => $this->makeClass('App\\SelfRef', 'lib/SelfRef.php', implements: ['App\\SelfRef']),
        ]);

        $result = $this->builder->build($index);

        // Self-referential edge filtered, so no outbound edges remain
        $this->assertArrayNotHasKey('App\\SelfRef', $result['dependencyMap']);
        $this->assertArrayNotHasKey('App\\SelfRef', $result['dependentsMap']);
    }

    public function testDuplicateEdgesAreDeduped(): void
    {
        // A class that injects the same type twice via different param names
        $index = $this->buildIndex(classes: [
            'App\\Service' => $this->makeClass('App\\Service', 'lib/Service.php', constructorParams: [
                new ConstructorParam('primary', 'App\\Logger', false),
                new ConstructorParam('secondary', 'App\\Logger', false),
            ]),
        ]);

        $result = $this->builder->build($index);

        $edges = $result['dependencyMap']['App\\Service']->edges;
        $injectEdges = array_filter($edges, fn($e) => $e->type === 'injects' && $e->fqcn === 'App\\Logger');
        $this->assertCount(1, $injectEdges, 'Duplicate inject edges should be deduped');
    }

    public function testVendorFqcnsHaveNullFile(): void
    {
        // App\\Service injects VendorInterface which is NOT in the class index
        $index = $this->buildIndex(classes: [
            'App\\Service' => $this->makeClass('App\\Service', 'lib/Service.php', constructorParams: [
                new ConstructorParam('logger', 'Vendor\\LoggerInterface', false),
            ]),
        ]);

        $result = $this->builder->build($index);

        $this->assertArrayHasKey('Vendor\\LoggerInterface', $result['dependentsMap']);
        $this->assertNull($result['dependentsMap']['Vendor\\LoggerInterface']->file);
    }

    public function testOrphansAreDetected(): void
    {
        $index = $this->buildIndex(classes: [
            'App\\Service' => $this->makeClass('App\\Service', 'lib/Service.php', implements: ['App\\ServiceInterface']),
            'App\\Orphan' => $this->makeClass('App\\Orphan', 'lib/Orphan.php'),
        ]);

        $result = $this->builder->build($index);

        $orphanFqcns = array_map(fn($o) => $o->fqcn, $result['orphans']);
        $this->assertContains('App\\Orphan', $orphanFqcns);
        $this->assertNotContains('App\\Service', $orphanFqcns);
    }

    public function testClassWithOnlyInboundEdgesIsNotOrphan(): void
    {
        // ServiceInterface has no outbound edges but is implemented by something
        $index = $this->buildIndex(classes: [
            'App\\Service' => $this->makeClass('App\\Service', 'lib/Service.php', implements: ['App\\ServiceInterface']),
            'App\\ServiceInterface' => $this->makeClass('App\\ServiceInterface', 'lib/ServiceInterface.php'),
        ]);

        $result = $this->builder->build($index);

        $orphanFqcns = array_map(fn($o) => $o->fqcn, $result['orphans']);
        $this->assertNotContains('App\\ServiceInterface', $orphanFqcns);
    }

    public function testEmptyIndexProducesEmptyResults(): void
    {
        $index = $this->buildIndex();

        $result = $this->builder->build($index);

        $this->assertEmpty($result['dependencyMap']);
        $this->assertEmpty($result['dependentsMap']);
        $this->assertEmpty($result['orphans']);
    }

    public function testFacadeWithNullProxiedInterfaceSkipped(): void
    {
        $index = $this->buildIndex(
            classes: [
                'App\\BrokenFacade' => $this->makeClass('App\\BrokenFacade', 'lib/BrokenFacade.php'),
            ],
            resolvedFacades: [
                'App\\BrokenFacade' => new ResolvedFacade('App\\BrokenFacade', 'lib/BrokenFacade.php', null),
            ]
        );

        $result = $this->builder->build($index);

        // No proxy edge should exist since proxiedInterface is null
        $this->assertArrayNotHasKey('App\\BrokenFacade', $result['dependencyMap']);
    }

    public function testMutationWithNullAdapterSkipped(): void
    {
        $index = $this->buildIndex(
            classes: [
                'App\\Mutator' => $this->makeClass('App\\Mutator', 'lib/Mutator.php'),
            ],
            resolvedMutations: [
                'App\\Mutator' => new ResolvedMutation('App\\Mutator', 'lib/Mutator.php', ['create'], false, null),
            ]
        );

        $result = $this->builder->build($index);

        // No mutates-via edge should exist since adapterClass is null
        $this->assertArrayNotHasKey('App\\Mutator', $result['dependencyMap']);
    }

    public function testMultipleEdgeTypesOnSameClass(): void
    {
        $index = $this->buildIndex(classes: [
            'App\\MyService' => $this->makeClass('App\\MyService', 'lib/MyService.php',
                implements: ['App\\ServiceInterface'],
                parentClass: 'App\\BaseService',
                traits: ['App\\Loggable'],
                constructorParams: [
                    new ConstructorParam('dep', 'App\\Dependency', false),
                ]
            ),
        ]);

        $result = $this->builder->build($index);

        $edges = $result['dependencyMap']['App\\MyService']->edges;
        $this->assertEdgeExists($edges, 'implements', 'App\\ServiceInterface');
        $this->assertEdgeExists($edges, 'extends', 'App\\BaseService');
        $this->assertEdgeExists($edges, 'uses-trait', 'App\\Loggable');
        $this->assertEdgeExists($edges, 'injects', 'App\\Dependency');
    }

    // --- Helpers ---

    /**
     * @param array<string, IndexedClass> $classes
     * @param array<string, IndexedInitializer> $initializers
     * @param IndexedApplication[] $applications
     * @param array<string, ResolvedFacade> $resolvedFacades
     * @param ResolvedTaskHandler[] $resolvedTaskHandlers
     * @param array<string, ResolvedMutation> $resolvedMutations
     */
    private function buildIndex(
        array $classes = [],
        array $initializers = [],
        array $applications = [],
        array $resolvedFacades = [],
        array $resolvedTaskHandlers = [],
        array $resolvedMutations = []
    ): ProjectIndex {
        return new ProjectIndex(
            projectPath: '/test',
            indexedAt: date('c'),
            classes: $classes,
            applications: $applications,
            initializers: $initializers,
            resolvedFacades: $resolvedFacades,
            resolvedTaskHandlers: $resolvedTaskHandlers,
            resolvedMutations: $resolvedMutations
        );
    }

    /**
     * @param string[] $implements
     * @param string[] $traits
     * @param ConstructorParam[] $constructorParams
     */
    private function makeClass(
        string $fqcn,
        string $file,
        array $implements = [],
        array $traits = [],
        array $constructorParams = [],
        ?string $parentClass = null
    ): IndexedClass {
        return new IndexedClass(
            fqcn: $fqcn,
            file: $file,
            line: 1,
            implements: $implements,
            traits: $traits,
            constructorParams: $constructorParams,
            isAbstract: false,
            parentClass: $parentClass,
            description: null
        );
    }

    /**
     * @param IndexedBinding[] $classDefinitions
     * @param array<string, string[]> $listeners
     */
    private function makeInitializer(
        string $fqcn,
        string $file,
        array $classDefinitions = [],
        array $listeners = []
    ): IndexedInitializer {
        return new IndexedInitializer(
            fqcn: $fqcn,
            file: $file,
            isVendor: false,
            implementedInterfaces: [],
            classDefinitions: $classDefinitions,
            controllers: [],
            listeners: $listeners,
            eventBindings: [],
            commands: [],
            mutations: [],
            taskHandlers: [],
            typeDefinitions: [],
            updates: [],
            facades: [],
            hasLoadCondition: false,
            isLoadable: false
        );
    }

    /**
     * @param \PHPNomad\Cli\Indexer\Models\GraphEdge[] $edges
     */
    private function assertEdgeExists(array $edges, string $type, string $fqcn): void
    {
        $found = $this->findEdge($edges, $type, $fqcn);
        $this->assertNotNull($found, "Expected edge type='$type' fqcn='$fqcn' not found");
    }

    /**
     * @param \PHPNomad\Cli\Indexer\Models\GraphEdge[] $edges
     */
    private function findEdge(array $edges, string $type, string $fqcn): ?\PHPNomad\Cli\Indexer\Models\GraphEdge
    {
        foreach ($edges as $edge) {
            if ($edge->type === $type && $edge->fqcn === $fqcn) {
                return $edge;
            }
        }

        return null;
    }
}
