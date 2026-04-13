<?php

namespace PHPNomad\Cli\Indexer;

use PHPNomad\Cli\Indexer\Models\IndexedClass;
use PHPNomad\Cli\Indexer\Models\ResolvedGraphQLType;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

class GraphQLTypeAnalyzer
{
    /**
     * Parse a TypeDefinition class to extract SDL and resolvers.
     */
    public function analyze(IndexedClass $class, string $basePath): ResolvedGraphQLType
    {
        $filePath = rtrim($basePath, '/') . '/' . $class->file;

        if (!file_exists($filePath)) {
            return new ResolvedGraphQLType($class->fqcn, $class->file, null, []);
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        $code = file_get_contents($filePath);

        if ($code === false) {
            return new ResolvedGraphQLType($class->fqcn, $class->file, null, []);
        }

        try {
            $ast = $parser->parse($code);
        } catch (\Throwable $e) {
            return new ResolvedGraphQLType($class->fqcn, $class->file, null, []);
        }

        if ($ast === null) {
            return new ResolvedGraphQLType($class->fqcn, $class->file, null, []);
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
            return new ResolvedGraphQLType($class->fqcn, $class->file, null, []);
        }

        $sdl = $this->extractSdl($classNode);
        $resolvers = $this->extractResolvers($classNode);

        return new ResolvedGraphQLType($class->fqcn, $class->file, $sdl, $resolvers);
    }

    /**
     * Extract the SDL string from getSdl().
     * Handles both regular strings and heredoc/nowdoc syntax.
     */
    protected function extractSdl(Stmt\Class_ $classNode): ?string
    {
        $method = $classNode->getMethod('getSdl');

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

    /**
     * Extract resolver mappings from getResolvers().
     * Expected format: ['Query' => ['fieldName' => ResolverClass::class]]
     *
     * @return array<string, array<string, string>>
     */
    protected function extractResolvers(Stmt\Class_ $classNode): array
    {
        $method = $classNode->getMethod('getResolvers');

        if ($method === null || $method->stmts === null) {
            return [];
        }

        $returnArray = null;

        foreach ($method->stmts as $stmt) {
            if ($stmt instanceof Stmt\Return_ && $stmt->expr instanceof Expr\Array_) {
                $returnArray = $stmt->expr;
                break;
            }
        }

        if ($returnArray === null) {
            return [];
        }

        $resolvers = [];

        foreach ($returnArray->items as $item) {
            if ($item === null || $item->key === null) {
                continue;
            }

            $typeName = null;
            if ($item->key instanceof Node\Scalar\String_) {
                $typeName = $item->key->value;
            }

            if ($typeName === null || !$item->value instanceof Expr\Array_) {
                continue;
            }

            $fields = [];

            foreach ($item->value->items as $fieldItem) {
                if ($fieldItem === null || $fieldItem->key === null) {
                    continue;
                }

                $fieldName = null;
                if ($fieldItem->key instanceof Node\Scalar\String_) {
                    $fieldName = $fieldItem->key->value;
                }

                $resolverClass = $this->resolveClassConstFetch($fieldItem->value);

                if ($fieldName !== null && $resolverClass !== null) {
                    $fields[$fieldName] = $resolverClass;
                }
            }

            if (!empty($fields)) {
                $resolvers[$typeName] = $fields;
            }
        }

        return $resolvers;
    }

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
}
