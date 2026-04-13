<?php

namespace PHPNomad\Cli\Indexer;

use PHPNomad\Cli\Indexer\Models\IndexedClass;
use PHPNomad\Cli\Indexer\Models\ResolvedMutation;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

class MutationAnalyzer
{
    protected const ADAPTER_TRAIT = 'PHPNomad\\Mutator\\Traits\\CanMutateFromAdapter';

    /**
     * Resolve a mutation handler into its details.
     *
     * @param list<string> $actions
     */
    public function analyze(IndexedClass $class, array $actions, string $basePath): ResolvedMutation
    {
        $usesAdapter = in_array(self::ADAPTER_TRAIT, $class->traits, true);
        $adapterClass = null;

        if ($usesAdapter) {
            $adapterClass = $this->extractAdapterClass($class, $basePath);
        }

        return new ResolvedMutation(
            $class->fqcn,
            $class->file,
            $actions,
            $usesAdapter,
            $adapterClass
        );
    }

    /**
     * Extract the MutationAdapter class from the $mutationAdapter property type hint.
     */
    protected function extractAdapterClass(IndexedClass $class, string $basePath): ?string
    {
        $filePath = rtrim($basePath, '/') . '/' . $class->file;

        if (!file_exists($filePath)) {
            return null;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        $code = file_get_contents($filePath);

        if ($code === false) {
            return null;
        }

        try {
            $ast = $parser->parse($code);
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

        // Look for constructor parameter or property named $mutationAdapter
        $method = $classNode->getMethod('__construct');

        if ($method !== null) {
            foreach ($method->params as $param) {
                if ($param->var instanceof Node\Expr\Variable
                    && $param->var->name === 'mutationAdapter'
                    && $param->type instanceof Node\Name
                ) {
                    return $param->type->toString();
                }
            }
        }

        // Check class properties
        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    if ($prop->name->name === 'mutationAdapter'
                        && $stmt->type instanceof Node\Name
                    ) {
                        return $stmt->type->toString();
                    }
                }
            }
        }

        return null;
    }
}
