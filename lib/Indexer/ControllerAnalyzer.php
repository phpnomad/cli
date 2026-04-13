<?php

namespace PHPNomad\Cli\Indexer;

use PHPNomad\Cli\Indexer\Models\IndexedClass;
use PHPNomad\Cli\Indexer\Models\ResolvedController;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

class ControllerAnalyzer
{
    protected const METHOD_MAP = [
        'Get' => 'GET',
        'Post' => 'POST',
        'Put' => 'PUT',
        'Delete' => 'DELETE',
        'Patch' => 'PATCH',
        'Options' => 'OPTIONS',
    ];

    protected const WITH_ENDPOINT_BASE = 'PHPNomad\\Rest\\Traits\\WithEndpointBase';
    protected const HAS_MIDDLEWARE = 'PHPNomad\\Rest\\Interfaces\\HasMiddleware';
    protected const HAS_VALIDATIONS = 'PHPNomad\\Rest\\Interfaces\\HasValidations';
    protected const HAS_INTERCEPTORS = 'PHPNomad\\Rest\\Interfaces\\HasInterceptors';

    /**
     * Parse a controller class to extract endpoint and method info.
     */
    public function analyze(IndexedClass $class, string $basePath): ResolvedController
    {
        $filePath = rtrim($basePath, '/') . '/' . $class->file;

        if (!file_exists($filePath)) {
            return $this->unresolved($class);
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $ast = $parser->parse(file_get_contents($filePath));
        } catch (\Throwable $e) {
            return $this->unresolved($class);
        }

        if ($ast === null) {
            return $this->unresolved($class);
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
            return $this->unresolved($class);
        }

        $usesEndpointBase = in_array(self::WITH_ENDPOINT_BASE, $class->traits, true);
        $hasMiddleware = in_array(self::HAS_MIDDLEWARE, $class->implements, true);
        $hasValidations = in_array(self::HAS_VALIDATIONS, $class->implements, true);
        $hasInterceptors = in_array(self::HAS_INTERCEPTORS, $class->implements, true);

        $endpoint = null;
        $endpointTail = null;

        if ($usesEndpointBase) {
            $endpointTail = $this->extractStringReturn($classNode, 'getEndpointTail');
        } else {
            $endpoint = $this->extractEndpoint($classNode);
        }

        $method = $this->extractMethod($classNode);

        return new ResolvedController(
            $class->fqcn,
            $class->file,
            $endpoint,
            $endpointTail,
            $method,
            $usesEndpointBase,
            $hasMiddleware,
            $hasValidations,
            $hasInterceptors
        );
    }

    /**
     * Extract the endpoint from getEndpoint() return value.
     */
    protected function extractEndpoint(Stmt\Class_ $classNode): ?string
    {
        $method = $classNode->getMethod('getEndpoint');

        if ($method === null || $method->stmts === null) {
            return null;
        }

        foreach ($method->stmts as $stmt) {
            if (!$stmt instanceof Stmt\Return_ || $stmt->expr === null) {
                continue;
            }

            // Simple string literal
            if ($stmt->expr instanceof Node\Scalar\String_) {
                return $stmt->expr->value;
            }

            // String concat — extract the string part(s)
            if ($stmt->expr instanceof Expr\BinaryOp\Concat) {
                return $this->extractConcatString($stmt->expr);
            }
        }

        return null;
    }

    /**
     * Extract string parts from a concat expression.
     * For patterns like $this->config->getEndpointBase() . '/generate',
     * returns the rightmost string literal.
     */
    protected function extractConcatString(Expr\BinaryOp\Concat $concat): ?string
    {
        if ($concat->right instanceof Node\Scalar\String_) {
            return $concat->right->value;
        }

        if ($concat->left instanceof Node\Scalar\String_) {
            return $concat->left->value;
        }

        return null;
    }

    /**
     * Extract the HTTP method from getMethod() return value.
     */
    protected function extractMethod(Stmt\Class_ $classNode): string
    {
        $method = $classNode->getMethod('getMethod');

        if ($method === null || $method->stmts === null) {
            return 'UNKNOWN';
        }

        foreach ($method->stmts as $stmt) {
            if (!$stmt instanceof Stmt\Return_ || $stmt->expr === null) {
                continue;
            }

            // String literal: return 'GET';
            if ($stmt->expr instanceof Node\Scalar\String_) {
                return strtoupper($stmt->expr->value);
            }

            // Class constant: return Method::Get;
            if ($stmt->expr instanceof Expr\ClassConstFetch
                && $stmt->expr->name instanceof Node\Identifier
            ) {
                $constName = $stmt->expr->name->name;

                return self::METHOD_MAP[$constName] ?? strtoupper($constName);
            }
        }

        return 'UNKNOWN';
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

    protected function unresolved(IndexedClass $class): ResolvedController
    {
        return new ResolvedController(
            $class->fqcn,
            $class->file,
            null,
            null,
            'UNKNOWN',
            false,
            false,
            false,
            false
        );
    }
}
