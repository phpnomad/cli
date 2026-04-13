<?php

namespace PHPNomad\Cli\Indexer;

use PHPNomad\Cli\Indexer\Models\IndexedClass;
use PHPNomad\Cli\Indexer\Models\ResolvedCommand;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

class CommandAnalyzer
{
    /**
     * Parse a command class to extract its signature and description.
     */
    public function analyze(IndexedClass $class, string $basePath): ResolvedCommand
    {
        $filePath = rtrim($basePath, '/') . '/' . $class->file;

        if (!file_exists($filePath)) {
            return new ResolvedCommand($class->fqcn, $class->file, null, null);
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        $code = file_get_contents($filePath);

        if ($code === false) {
            return new ResolvedCommand($class->fqcn, $class->file, null, null);
        }

        try {
            $ast = $parser->parse($code);
        } catch (\Throwable $e) {
            return new ResolvedCommand($class->fqcn, $class->file, null, null);
        }

        if ($ast === null) {
            return new ResolvedCommand($class->fqcn, $class->file, null, null);
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
            return new ResolvedCommand($class->fqcn, $class->file, null, null);
        }

        $signature = $this->extractStringReturn($classNode, 'getSignature');
        $description = $this->extractStringReturn($classNode, 'getDescription');

        return new ResolvedCommand($class->fqcn, $class->file, $signature, $description);
    }

    /**
     * Extract a simple string return from a method.
     */
    protected function extractStringReturn(Stmt\Class_ $classNode, string $methodName): ?string
    {
        $method = $classNode->getMethod($methodName);

        if ($method === null || $method->stmts === null) {
            return null;
        }

        foreach ($method->stmts as $stmt) {
            if ($stmt instanceof Stmt\Return_
                && $stmt->expr instanceof Node\Scalar\String_
            ) {
                return $stmt->expr->value;
            }
        }

        return null;
    }
}
