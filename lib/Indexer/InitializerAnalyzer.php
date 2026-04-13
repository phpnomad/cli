<?php

namespace PHPNomad\Cli\Indexer;

use PHPNomad\Cli\Indexer\Models\IndexedBinding;
use PHPNomad\Cli\Indexer\Models\IndexedClass;
use PHPNomad\Cli\Indexer\Models\IndexedInitializer;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

class InitializerAnalyzer
{
    protected const INTERFACE_MAP = [
        'PHPNomad\\Loader\\Interfaces\\HasClassDefinitions' => 'getClassDefinitions',
        'PHPNomad\\Rest\\Interfaces\\HasControllers' => 'getControllers',
        'PHPNomad\\Events\\Interfaces\\HasListeners' => 'getListeners',
        'PHPNomad\\Events\\Interfaces\\HasEventBindings' => 'getEventBindings',
        'PHPNomad\\Console\\Interfaces\\HasCommands' => 'getCommands',
        'PHPNomad\\Mutator\\Interfaces\\HasMutations' => 'getMutations',
        'PHPNomad\\Tasks\\Interfaces\\HasTaskHandlers' => 'getTaskHandlers',
        'PHPNomad\\GraphQL\\Interfaces\\HasTypeDefinitions' => 'getTypeDefinitions',
        'PHPNomad\\Update\\Interfaces\\HasUpdates' => 'getRoutines',
        'PHPNomad\\Facade\\Interfaces\\HasFacades' => 'getFacades',
    ];

    /**
     * Analyze an Initializer class and extract everything it contributes.
     */
    public function analyze(IndexedClass $class, string $basePath): IndexedInitializer
    {
        $filePath = rtrim($basePath, '/') . '/' . $class->file;

        if (!file_exists($filePath)) {
            return $this->emptyInitializer($class);
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $ast = $parser->parse(file_get_contents($filePath));
        } catch (\Throwable $e) {
            return $this->emptyInitializer($class);
        }

        if ($ast === null) {
            return $this->emptyInitializer($class);
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);

        $nodeFinder = new NodeFinder();
        $classNodes = $nodeFinder->findInstanceOf($ast, Stmt\Class_::class);
        $classNode = null;

        foreach ($classNodes as $node) {
            if ($node->namespacedName !== null && $node->namespacedName->toString() === $class->fqcn) {
                $classNode = $node;
                break;
            }
        }

        if ($classNode === null) {
            return $this->emptyInitializer($class);
        }

        $implements = $class->implements;

        $classDefinitions = [];
        if ($this->hasInterface($implements, 'PHPNomad\\Loader\\Interfaces\\HasClassDefinitions')) {
            $classDefinitions = $this->extractClassDefinitions($classNode, $class);
        }

        $controllers = [];
        if ($this->hasInterface($implements, 'PHPNomad\\Rest\\Interfaces\\HasControllers')) {
            $controllers = $this->extractClassRefs($classNode, 'getControllers');
        }

        $listeners = [];
        if ($this->hasInterface($implements, 'PHPNomad\\Events\\Interfaces\\HasListeners')) {
            $listeners = $this->extractMappedClassRefs($classNode, 'getListeners');
        }

        $eventBindings = [];
        if ($this->hasInterface($implements, 'PHPNomad\\Events\\Interfaces\\HasEventBindings')) {
            $eventBindings = $this->extractMappedClassRefs($classNode, 'getEventBindings');
        }

        $commands = [];
        if ($this->hasInterface($implements, 'PHPNomad\\Console\\Interfaces\\HasCommands')) {
            $commands = $this->extractClassRefs($classNode, 'getCommands');
        }

        $mutations = [];
        if ($this->hasInterface($implements, 'PHPNomad\\Mutator\\Interfaces\\HasMutations')) {
            $mutations = $this->extractMappedClassRefs($classNode, 'getMutations');
        }

        $taskHandlers = [];
        if ($this->hasInterface($implements, 'PHPNomad\\Tasks\\Interfaces\\HasTaskHandlers')) {
            $taskHandlers = $this->extractMappedClassRefs($classNode, 'getTaskHandlers');
        }

        $typeDefinitions = [];
        if ($this->hasInterface($implements, 'PHPNomad\\GraphQL\\Interfaces\\HasTypeDefinitions')) {
            $typeDefinitions = $this->extractClassRefs($classNode, 'getTypeDefinitions');
        }

        $updates = [];
        if ($this->hasInterface($implements, 'PHPNomad\\Update\\Interfaces\\HasUpdates')) {
            $updates = $this->extractClassRefs($classNode, 'getRoutines');
        }

        $facades = [];
        if ($this->hasInterface($implements, 'PHPNomad\\Facade\\Interfaces\\HasFacades')) {
            $facades = $this->extractClassRefs($classNode, 'getFacades');
        }

        $hasLoadCondition = $this->hasInterface($implements, 'PHPNomad\\Loader\\Interfaces\\HasLoadCondition');
        $isLoadable = $this->hasInterface($implements, 'PHPNomad\\Loader\\Interfaces\\Loadable');
        $isVendor = str_contains($class->file, 'vendor/');

        return new IndexedInitializer(
            $class->fqcn,
            $class->file,
            $isVendor,
            $implements,
            $classDefinitions,
            $controllers,
            $listeners,
            $eventBindings,
            $commands,
            $mutations,
            $taskHandlers,
            $typeDefinitions,
            $updates,
            $facades,
            $hasLoadCondition,
            $isLoadable
        );
    }

    /**
     * Extract getClassDefinitions() return: concrete => abstract(s) bindings.
     *
     * @return IndexedBinding[]
     */
    protected function extractClassDefinitions(Stmt\Class_ $classNode, IndexedClass $class): array
    {
        $method = $classNode->getMethod('getClassDefinitions');

        if ($method === null) {
            return [];
        }

        $returnArray = $this->findReturnArray($method);

        if ($returnArray === null) {
            return [];
        }

        $bindings = [];

        foreach ($returnArray->items as $item) {
            if ($item === null || $item->key === null) {
                continue;
            }

            $concrete = $this->resolveClassConstFetch($item->key);

            if ($concrete === null) {
                continue;
            }

            $abstracts = [];

            if ($item->value instanceof Expr\Array_) {
                foreach ($item->value->items as $subItem) {
                    if ($subItem === null) {
                        continue;
                    }

                    $abstract = $this->resolveClassConstFetch($subItem->value);

                    if ($abstract !== null) {
                        $abstracts[] = $abstract;
                    }
                }
            } else {
                $abstract = $this->resolveClassConstFetch($item->value);

                if ($abstract !== null) {
                    $abstracts[] = $abstract;
                }
            }

            if (!empty($abstracts)) {
                $bindings[] = new IndexedBinding(
                    $concrete,
                    $abstracts,
                    $class->fqcn,
                    $class->file,
                    'declarative'
                );
            }
        }

        return $bindings;
    }

    /**
     * Extract a flat array of class references from a method like getControllers().
     *
     * @return string[]
     */
    protected function extractClassRefs(Stmt\Class_ $classNode, string $methodName): array
    {
        $method = $classNode->getMethod($methodName);

        if ($method === null) {
            return [];
        }

        $returnArray = $this->findReturnArray($method);

        if ($returnArray === null) {
            return [];
        }

        $refs = [];

        foreach ($returnArray->items as $item) {
            if ($item === null) {
                continue;
            }

            $ref = $this->resolveClassConstFetch($item->value);

            if ($ref !== null) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    /**
     * Extract a mapped array: event => handler(s) from methods like getListeners().
     *
     * @return array<string, string[]>
     */
    protected function extractMappedClassRefs(Stmt\Class_ $classNode, string $methodName): array
    {
        $method = $classNode->getMethod($methodName);

        if ($method === null) {
            return [];
        }

        $returnArray = $this->findReturnArray($method);

        if ($returnArray === null) {
            return [];
        }

        $map = [];

        foreach ($returnArray->items as $item) {
            if ($item === null || $item->key === null) {
                continue;
            }

            $key = $this->resolveClassConstFetch($item->key);

            if ($key === null) {
                if ($item->key instanceof Node\Scalar\String_) {
                    $key = $item->key->value;
                } else {
                    continue;
                }
            }

            $values = [];

            if ($item->value instanceof Expr\Array_) {
                foreach ($item->value->items as $subItem) {
                    if ($subItem === null) {
                        continue;
                    }

                    $val = $this->resolveClassConstFetch($subItem->value);

                    if ($val !== null) {
                        $values[] = $val;
                    }
                }
            } else {
                $val = $this->resolveClassConstFetch($item->value);

                if ($val !== null) {
                    $values[] = $val;
                }
            }

            if (!empty($values)) {
                $map[$key] = $values;
            }
        }

        return $map;
    }

    /**
     * Find the Array_ node in a method's return statement.
     */
    protected function findReturnArray(Stmt\ClassMethod $method): ?Expr\Array_
    {
        if ($method->stmts === null) {
            return null;
        }

        foreach ($method->stmts as $stmt) {
            if ($stmt instanceof Stmt\Return_ && $stmt->expr instanceof Expr\Array_) {
                return $stmt->expr;
            }
        }

        return null;
    }

    /**
     * Resolve a Foo::class expression to a FQCN string.
     */
    protected function resolveClassConstFetch(Node\Expr $expr): ?string
    {
        if ($expr instanceof Expr\ClassConstFetch
            && $expr->name instanceof Node\Identifier
            && $expr->name->name === 'class'
            && $expr->class instanceof Node\Name
        ) {
            return $expr->class->toString();
        }

        return null;
    }

    protected function hasInterface(array $implements, string $fqcn): bool
    {
        return in_array($fqcn, $implements, true);
    }

    protected function emptyInitializer(IndexedClass $class): IndexedInitializer
    {
        return new IndexedInitializer(
            $class->fqcn,
            $class->file,
            str_contains($class->file, 'vendor/'),
            $class->implements,
            [], [], [], [], [], [], [], [], [], [],
            false,
            false
        );
    }
}
