<?php

namespace PHPNomad\Cli\Indexer;

use PHPNomad\Cli\Indexer\Models\IndexedClass;
use PHPNomad\Cli\Indexer\Models\ResolvedEvent;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

class EventAnalyzer
{
    protected const EVENT_INTERFACE = 'PHPNomad\\Events\\Interfaces\\Event';

    /**
     * Check if a class is an Event.
     */
    public function isEvent(IndexedClass $class): bool
    {
        return in_array(self::EVENT_INTERFACE, $class->implements, true);
    }

    /**
     * Parse an Event class to extract its ID and properties.
     */
    public function analyze(IndexedClass $class, string $basePath): ResolvedEvent
    {
        $filePath = rtrim($basePath, '/') . '/' . $class->file;

        if (!file_exists($filePath)) {
            return new ResolvedEvent($class->fqcn, $class->file, null, []);
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $ast = $parser->parse(file_get_contents($filePath));
        } catch (\Throwable $e) {
            return new ResolvedEvent($class->fqcn, $class->file, null, []);
        }

        if ($ast === null) {
            return new ResolvedEvent($class->fqcn, $class->file, null, []);
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
            return new ResolvedEvent($class->fqcn, $class->file, null, []);
        }

        $eventId = $this->extractGetId($classNode);

        // Extract properties from constructor params (already in IndexedClass)
        $properties = [];
        foreach ($class->constructorParams as $param) {
            $properties[] = [
                'name' => $param->name,
                'type' => $param->type,
            ];
        }

        return new ResolvedEvent($class->fqcn, $class->file, $eventId, $properties);
    }

    /**
     * Extract the return value of static getId(): string.
     */
    protected function extractGetId(Stmt\Class_ $classNode): ?string
    {
        $method = $classNode->getMethod('getId');

        if ($method === null || $method->stmts === null) {
            return null;
        }

        foreach ($method->stmts as $stmt) {
            if ($stmt instanceof Stmt\Return_ && $stmt->expr instanceof Node\Scalar\String_) {
                return $stmt->expr->value;
            }
        }

        return null;
    }
}
