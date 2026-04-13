<?php

namespace PHPNomad\Cli\Indexer;

use PHPNomad\Cli\Indexer\Models\IndexedClass;
use PHPNomad\Cli\Indexer\Models\ResolvedTaskHandler;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

class TaskHandlerAnalyzer
{
    /**
     * Resolve a task handler mapping into its details.
     */
    public function analyze(
        IndexedClass $handlerClass,
        string $taskClassFqcn,
        ?IndexedClass $taskClass,
        string $basePath
    ): ResolvedTaskHandler {
        $taskId = null;
        $taskFile = $taskClass?->file;

        if ($taskClass !== null) {
            $taskId = $this->extractStaticStringReturn($taskClass, 'getId', $basePath);
        }

        return new ResolvedTaskHandler(
            $handlerClass->fqcn,
            $handlerClass->file,
            $taskClassFqcn,
            $taskId,
            $taskFile
        );
    }

    /**
     * Extract a string return value from a static method.
     */
    protected function extractStaticStringReturn(IndexedClass $class, string $methodName, string $basePath): ?string
    {
        $filePath = rtrim($basePath, '/') . '/' . $class->file;

        if (!file_exists($filePath)) {
            return null;
        }

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
