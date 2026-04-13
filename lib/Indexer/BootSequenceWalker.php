<?php

namespace PHPNomad\Cli\Indexer;

use PHPNomad\Cli\Indexer\Models\BootstrapperCall;
use PHPNomad\Cli\Indexer\Models\IndexedApplication;
use PHPNomad\Cli\Indexer\Models\IndexedBinding;
use PHPNomad\Cli\Indexer\Models\IndexedClass;
use PHPNomad\Cli\Indexer\Models\InitializerReference;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

class BootSequenceWalker
{
    protected const BOOTSTRAPPER_FQCN = 'PHPNomad\\Loader\\Bootstrapper';

    /**
     * Find all Application classes and reconstruct their boot sequences.
     *
     * @param string $path Project root
     * @param array<string, IndexedClass> $classIndex
     * @return IndexedApplication[]
     */
    public function walk(string $path, array $classIndex): array
    {
        $applications = [];

        foreach ($classIndex as $fqcn => $class) {
            $filePath = rtrim($path, '/') . '/' . $class->file;

            if (!file_exists($filePath)) {
                continue;
            }

            $result = $this->analyzeFile($filePath, $class, $path);

            if ($result !== null) {
                $applications[] = $result;
            }
        }

        return $applications;
    }

    protected function analyzeFile(string $filePath, IndexedClass $class, string $basePath): ?IndexedApplication
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $ast = $parser->parse(file_get_contents($filePath));
        } catch (\Throwable $e) {
            return null;
        }

        if ($ast === null) {
            return null;
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
            return null;
        }

        // Check if this class instantiates a Bootstrapper anywhere
        $bootstrapperNews = $nodeFinder->find($classNode, function (Node $node) {
            return $node instanceof Expr\New_
                && $node->class instanceof Node\Name
                && $node->class->toString() === self::BOOTSTRAPPER_FQCN;
        });

        if (empty($bootstrapperNews)) {
            return null;
        }

        // This is an Application class. Parse its methods.
        $preBindings = [];
        $postBindings = [];
        $bootstrapperCalls = [];

        foreach ($classNode->getMethods() as $method) {
            $methodName = $method->name->name;
            $methodResults = $this->analyzeMethod($method, $classNode, $class);

            foreach ($methodResults['bootstrapperCalls'] as $call) {
                $bootstrapperCalls[] = $call;
            }

            // Bindings before the first Bootstrapper call are pre-bootstrap
            // Bindings after the last Bootstrapper call are post-bootstrap
            foreach ($methodResults['bindings'] as $binding) {
                $preBindings[] = $binding;
            }

            foreach ($methodResults['postBindings'] as $binding) {
                $postBindings[] = $binding;
            }
        }

        return new IndexedApplication(
            $class->fqcn,
            $class->file,
            $preBindings,
            $bootstrapperCalls,
            $postBindings
        );
    }

    /**
     * @return array{bootstrapperCalls: BootstrapperCall[], bindings: IndexedBinding[], postBindings: IndexedBinding[]}
     */
    protected function analyzeMethod(Stmt\ClassMethod $method, Stmt\Class_ $classNode, IndexedClass $class): array
    {
        $methodName = $method->name->name;
        $bootstrapperCalls = [];
        $preBindings = [];
        $postBindings = [];
        $seenBootstrapper = false;

        if ($method->stmts === null) {
            return ['bootstrapperCalls' => [], 'bindings' => [], 'postBindings' => []];
        }

        foreach ($method->stmts as $stmt) {
            $this->walkStatement($stmt, $methodName, $classNode, $class, $bootstrapperCalls, $preBindings, $postBindings, $seenBootstrapper);
        }

        return [
            'bootstrapperCalls' => $bootstrapperCalls,
            'bindings' => $preBindings,
            'postBindings' => $postBindings,
        ];
    }

    protected function walkStatement(
        Node $stmt,
        string $methodName,
        Stmt\Class_ $classNode,
        IndexedClass $class,
        array &$bootstrapperCalls,
        array &$preBindings,
        array &$postBindings,
        bool &$seenBootstrapper
    ): void {
        // Check for new Bootstrapper(...) — could be standalone or chained with ->load()
        $bootstrapperNew = $this->findBootstrapperNew($stmt);

        if ($bootstrapperNew !== null) {
            $refs = $this->extractInitializerRefs($bootstrapperNew, $classNode);
            $bootstrapperCalls[] = new BootstrapperCall($methodName, $bootstrapperNew->getStartLine(), $refs);
            $seenBootstrapper = true;
            return;
        }

        // Check for $this->container->bind() or ->bindFactory() calls
        $binding = $this->extractContainerBinding($stmt, $class);

        if ($binding !== null) {
            if ($seenBootstrapper) {
                $postBindings[] = $binding;
            } else {
                $preBindings[] = $binding;
            }
            return;
        }

        // Check for variable assignments containing Bootstrapper new — e.g., $bootstrapper = new Bootstrapper(...)
        if ($stmt instanceof Stmt\Expression && $stmt->expr instanceof Expr\Assign) {
            $bootstrapperNew = $this->findBootstrapperNew($stmt->expr->expr);

            if ($bootstrapperNew !== null) {
                $refs = $this->extractInitializerRefs($bootstrapperNew, $classNode);
                $bootstrapperCalls[] = new BootstrapperCall($methodName, $bootstrapperNew->getStartLine(), $refs);
                $seenBootstrapper = true;
            }
        }
    }

    /**
     * Find a new Bootstrapper() expression within a statement.
     */
    protected function findBootstrapperNew(Node $node): ?Expr\New_
    {
        // Direct: new Bootstrapper(...)
        if ($node instanceof Expr\New_
            && $node->class instanceof Node\Name
            && $node->class->toString() === self::BOOTSTRAPPER_FQCN
        ) {
            return $node;
        }

        // Chained: (new Bootstrapper(...))->load()
        if ($node instanceof Stmt\Expression) {
            $node = $node->expr;
        }

        if ($node instanceof Expr\MethodCall && $node->var instanceof Expr\New_) {
            return $this->findBootstrapperNew($node->var);
        }

        // Assignment: $var = new Bootstrapper(...)
        if ($node instanceof Expr\Assign) {
            return $this->findBootstrapperNew($node->expr);
        }

        return null;
    }

    /**
     * Extract the ordered list of InitializerReferences from a new Bootstrapper(...) call.
     */
    protected function extractInitializerRefs(Expr\New_ $newExpr, Stmt\Class_ $classNode): array
    {
        $args = $newExpr->args;

        // First arg is the container, skip it
        $initArgs = array_slice($args, 1);
        $refs = [];

        foreach ($initArgs as $arg) {
            if ($arg instanceof Node\Arg && $arg->unpack) {
                // Spread: ...$variable or ...$this->method()
                $spreadRefs = $this->resolveSpreadArg($arg->value, $classNode);
                array_push($refs, ...$spreadRefs);
            } elseif ($arg instanceof Node\Arg) {
                $argRefs = $this->resolveInitializerArg($arg->value, $classNode);
                array_push($refs, ...$argRefs);
            }
        }

        return $refs;
    }

    /**
     * Resolve a spread argument (...$var or ...$this->method()).
     *
     * @return InitializerReference[]
     */
    protected function resolveSpreadArg(Expr $expr, Stmt\Class_ $classNode): array
    {
        // ...$this->getCoreInitializers()
        if ($expr instanceof Expr\MethodCall
            && $expr->var instanceof Expr\Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Node\Identifier
        ) {
            return $this->resolveThisMethodCall($expr->name->name, $classNode);
        }

        // ...$variable — trace back to assignment
        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            return $this->resolveVariableInClass($expr->name, $classNode);
        }

        return [new InitializerReference(null, '$' . ($expr instanceof Expr\Variable ? $expr->name : 'unknown'), true)];
    }

    /**
     * Resolve a single Bootstrapper constructor arg.
     *
     * @return InitializerReference[]
     */
    protected function resolveInitializerArg(Expr $expr, Stmt\Class_ $classNode): array
    {
        // new SomeInitializer()
        if ($expr instanceof Expr\New_ && $expr->class instanceof Node\Name) {
            return [new InitializerReference($expr->class->toString(), 'inline', false)];
        }

        // $this->getCoreInitializers() — not spread, passed directly
        if ($expr instanceof Expr\MethodCall
            && $expr->var instanceof Expr\Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Node\Identifier
        ) {
            return $this->resolveThisMethodCall($expr->name->name, $classNode);
        }

        // $variable
        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            return $this->resolveVariableInClass($expr->name, $classNode);
        }

        return [new InitializerReference(null, 'unresolved', true)];
    }

    /**
     * Follow a $this->method() call to extract its returned Initializer list.
     *
     * @return InitializerReference[]
     */
    protected function resolveThisMethodCall(string $methodName, Stmt\Class_ $classNode): array
    {
        $method = $classNode->getMethod($methodName);

        if ($method === null || $method->stmts === null) {
            return [new InitializerReference(null, $methodName . '()', true)];
        }

        // Find the return statement
        foreach ($method->stmts as $stmt) {
            if (!($stmt instanceof Stmt\Return_)) {
                continue;
            }

            if ($stmt->expr instanceof Expr\Array_) {
                return $this->extractNewExprsFromArray($stmt->expr, $methodName);
            }
        }

        return [new InitializerReference(null, $methodName . '()', true)];
    }

    /**
     * Resolve a $variable reference by searching for its assignment in the class.
     *
     * @return InitializerReference[]
     */
    protected function resolveVariableInClass(string $varName, Stmt\Class_ $classNode): array
    {
        // Search all methods for assignments to this variable
        foreach ($classNode->getMethods() as $method) {
            if ($method->stmts === null) {
                continue;
            }

            foreach ($method->stmts as $stmt) {
                if (!($stmt instanceof Stmt\Expression) || !($stmt->expr instanceof Expr\Assign)) {
                    continue;
                }

                $assign = $stmt->expr;

                if (!($assign->var instanceof Expr\Variable) || $assign->var->name !== $varName) {
                    continue;
                }

                // $var = array_merge(...)
                if ($assign->expr instanceof Expr\FuncCall
                    && $assign->expr->name instanceof Node\Name
                    && $assign->expr->name->toString() === 'array_merge'
                ) {
                    return $this->resolveArrayMerge($assign->expr, $classNode);
                }

                // $var = [new Foo(), new Bar()]
                if ($assign->expr instanceof Expr\Array_) {
                    return $this->extractNewExprsFromArray($assign->expr, '$' . $varName);
                }
            }

            // Also check method parameters — if it's a parameter, it's dynamic
            foreach ($method->params as $param) {
                if ($param->var->name === $varName) {
                    return [new InitializerReference(null, '$' . $varName, true)];
                }
            }
        }

        return [new InitializerReference(null, '$' . $varName, true)];
    }

    /**
     * Resolve an array_merge(...) call into ordered Initializer references.
     *
     * @return InitializerReference[]
     */
    protected function resolveArrayMerge(Expr\FuncCall $funcCall, Stmt\Class_ $classNode): array
    {
        $refs = [];

        foreach ($funcCall->args as $arg) {
            if (!($arg instanceof Node\Arg)) {
                continue;
            }

            $expr = $arg->value;

            // Array literal: [new Foo(), new Bar()]
            if ($expr instanceof Expr\Array_) {
                $arrayRefs = $this->extractNewExprsFromArray($expr, 'inline');
                array_push($refs, ...$arrayRefs);
                continue;
            }

            // $this->getCoreInitializers()
            if ($expr instanceof Expr\MethodCall
                && $expr->var instanceof Expr\Variable
                && $expr->var->name === 'this'
                && $expr->name instanceof Node\Identifier
            ) {
                $methodRefs = $this->resolveThisMethodCall($expr->name->name, $classNode);
                array_push($refs, ...$methodRefs);
                continue;
            }

            // $variable
            if ($expr instanceof Expr\Variable && is_string($expr->name)) {
                $varRefs = $this->resolveVariableInClass($expr->name, $classNode);
                array_push($refs, ...$varRefs);
                continue;
            }

            $refs[] = new InitializerReference(null, 'unresolved', true);
        }

        return $refs;
    }

    /**
     * Extract new SomeClass() expressions from an array literal.
     *
     * @return InitializerReference[]
     */
    protected function extractNewExprsFromArray(Expr\Array_ $array, string $source): array
    {
        $refs = [];

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->value instanceof Expr\New_ && $item->value->class instanceof Node\Name) {
                $refs[] = new InitializerReference($item->value->class->toString(), $source, false);
            } else {
                $refs[] = new InitializerReference(null, $source, true);
            }
        }

        return $refs;
    }

    /**
     * Try to extract a container bind/bindFactory call.
     */
    protected function extractContainerBinding(Node $stmt, IndexedClass $class): ?IndexedBinding
    {
        if (!($stmt instanceof Stmt\Expression)) {
            return null;
        }

        $expr = $stmt->expr;

        // $this->container->bind(...) or $this->container->bindFactory(...)
        if (!($expr instanceof Expr\MethodCall)) {
            return null;
        }

        if (!($expr->name instanceof Node\Identifier)) {
            return null;
        }

        $methodName = $expr->name->name;

        if ($methodName !== 'bind' && $methodName !== 'bindFactory') {
            return null;
        }

        // Verify it's called on $this->container
        if (!$this->isContainerAccess($expr->var)) {
            return null;
        }

        if ($methodName === 'bind') {
            return $this->extractBindCall($expr, $class);
        }

        return $this->extractBindFactoryCall($expr, $class);
    }

    protected function isContainerAccess(Expr $expr): bool
    {
        // $this->container
        if ($expr instanceof Expr\PropertyFetch
            && $expr->var instanceof Expr\Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Node\Identifier
            && $expr->name->name === 'container'
        ) {
            return true;
        }

        return false;
    }

    /**
     * Extract from: $this->container->bind(Concrete::class, Abstract::class, ...moreAbstracts)
     */
    protected function extractBindCall(Expr\MethodCall $call, IndexedClass $class): ?IndexedBinding
    {
        $args = $call->args;

        if (count($args) < 2) {
            return null;
        }

        $concrete = $this->resolveClassConstFetch($args[0]->value);

        if ($concrete === null) {
            return null;
        }

        $abstracts = [];

        for ($i = 1; $i < count($args); $i++) {
            $abstract = $this->resolveClassConstFetch($args[$i]->value);

            if ($abstract !== null) {
                $abstracts[] = $abstract;
            }
        }

        if (empty($abstracts)) {
            return null;
        }

        return new IndexedBinding($concrete, $abstracts, $class->fqcn, $class->file, 'imperative');
    }

    /**
     * Extract from: $this->container->bindFactory(Abstract::class, fn() => ...)
     */
    protected function extractBindFactoryCall(Expr\MethodCall $call, IndexedClass $class): ?IndexedBinding
    {
        $args = $call->args;

        if (count($args) < 1) {
            return null;
        }

        $abstract = $this->resolveClassConstFetch($args[0]->value);

        if ($abstract === null) {
            return null;
        }

        return new IndexedBinding($abstract, [$abstract], $class->fqcn, $class->file, 'imperative');
    }

    protected function resolveClassConstFetch(Expr $expr): ?string
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
}
