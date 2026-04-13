<?php

namespace PHPNomad\Cli\Indexer;

use PHPNomad\Cli\Indexer\Models\IndexedClass;
use PHPNomad\Cli\Indexer\Models\ResolvedFacade;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

class FacadeAnalyzer
{
    /**
     * Parse a Facade class to extract the interface it proxies.
     */
    public function analyze(IndexedClass $class, string $basePath): ResolvedFacade
    {
        $filePath = rtrim($basePath, '/') . '/' . $class->file;

        if (!file_exists($filePath)) {
            return new ResolvedFacade($class->fqcn, $class->file, null);
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $ast = $parser->parse(file_get_contents($filePath));
        } catch (\Throwable $e) {
            return new ResolvedFacade($class->fqcn, $class->file, null);
        }

        if ($ast === null) {
            return new ResolvedFacade($class->fqcn, $class->file, null);
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
            return new ResolvedFacade($class->fqcn, $class->file, null);
        }

        $proxiedInterface = $this->extractClassReturn($classNode, 'abstractInstance');

        return new ResolvedFacade($class->fqcn, $class->file, $proxiedInterface);
    }

    /**
     * Extract a class reference return (Foo::class or string literal) from a method.
     */
    protected function extractClassReturn(Stmt\Class_ $classNode, string $methodName): ?string
    {
        $method = $classNode->getMethod($methodName);

        if ($method === null || $method->stmts === null) {
            return null;
        }

        foreach ($method->stmts as $stmt) {
            if (!$stmt instanceof Stmt\Return_ || $stmt->expr === null) {
                continue;
            }

            if ($stmt->expr instanceof Expr\ClassConstFetch
                && $stmt->expr->name instanceof Node\Identifier
                && $stmt->expr->name->name === 'class'
                && $stmt->expr->class instanceof Node\Name
            ) {
                return $stmt->expr->class->toString();
            }

            if ($stmt->expr instanceof Node\Scalar\String_) {
                return $stmt->expr->value;
            }
        }

        return null;
    }
}
