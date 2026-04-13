<?php

namespace PHPNomad\Cli\Indexer;

use PHPNomad\Cli\Indexer\Models\IndexedClass;
use PHPNomad\Cli\Indexer\Models\ResolvedTable;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

class TableAnalyzer
{
    protected const TABLE_ABSTRACT = 'PHPNomad\\Database\\Abstracts\\Table';

    protected const FACTORY_COLUMNS = [
        'PHPNomad\\Database\\Factories\\PrimaryKeyFactory' => ['name' => 'id', 'type' => 'BIGINT', 'factory' => 'PrimaryKeyFactory'],
        'PHPNomad\\Database\\Factories\\Columns\\PrimaryKeyFactory' => ['name' => 'id', 'type' => 'BIGINT', 'factory' => 'PrimaryKeyFactory'],
        'PHPNomad\\Database\\Factories\\DateCreatedFactory' => ['name' => 'dateCreated', 'type' => 'TIMESTAMP', 'factory' => 'DateCreatedFactory'],
        'PHPNomad\\Database\\Factories\\Columns\\DateCreatedFactory' => ['name' => 'dateCreated', 'type' => 'TIMESTAMP', 'factory' => 'DateCreatedFactory'],
        'PHPNomad\\Database\\Factories\\DateModifiedFactory' => ['name' => 'dateModified', 'type' => 'TIMESTAMP', 'factory' => 'DateModifiedFactory'],
        'PHPNomad\\Database\\Factories\\Columns\\DateModifiedFactory' => ['name' => 'dateModified', 'type' => 'TIMESTAMP', 'factory' => 'DateModifiedFactory'],
    ];

    /**
     * Check if a class is a Table definition.
     */
    public function isTable(IndexedClass $class): bool
    {
        return $class->parentClass === self::TABLE_ABSTRACT;
    }

    /**
     * Parse a Table class to extract its schema.
     */
    public function analyze(IndexedClass $class, string $basePath): ResolvedTable
    {
        $filePath = rtrim($basePath, '/') . '/' . $class->file;

        if (!file_exists($filePath)) {
            return new ResolvedTable($class->fqcn, $class->file, null, []);
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        $code = file_get_contents($filePath);

        if ($code === false) {
            return new ResolvedTable($class->fqcn, $class->file, null, []);
        }

        try {
            $ast = $parser->parse($code);
        } catch (\Throwable $e) {
            return new ResolvedTable($class->fqcn, $class->file, null, []);
        }

        if ($ast === null) {
            return new ResolvedTable($class->fqcn, $class->file, null, []);
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
            return new ResolvedTable($class->fqcn, $class->file, null, []);
        }

        $tableName = $this->extractStringReturn($classNode, 'getUnprefixedName')
            ?? $this->extractStringReturn($classNode, 'getName');

        $columns = $this->extractColumns($classNode);

        return new ResolvedTable($class->fqcn, $class->file, $tableName, $columns);
    }

    /**
     * Extract column definitions from getColumns() return array.
     *
     * @return list<array{name: string, type: string, typeArgs: ?list<int|float>, attributes: list<string>, factory: ?string}>
     */
    protected function extractColumns(Stmt\Class_ $classNode): array
    {
        $method = $classNode->getMethod('getColumns');

        if ($method === null || $method->stmts === null) {
            return [];
        }

        $returnArray = $this->findArrayInReturn($method);

        if ($returnArray === null) {
            return [];
        }

        $columns = [];

        foreach ($returnArray->items as $item) {
            if ($item === null) {
                continue;
            }

            $column = $this->parseColumnExpression($item->value);

            if ($column !== null) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * Parse a single column expression: new Column(...) or (new Factory())->toColumn()
     *
     * @return ?array{name: string, type: string, typeArgs: ?list<int|float>, attributes: list<string>, factory: ?string}
     */
    protected function parseColumnExpression(Node\Expr $expr): ?array
    {
        // Direct Column construction: new Column('name', 'VARCHAR', [255], 'NOT NULL')
        if ($expr instanceof Expr\New_ && $expr->class instanceof Node\Name) {
            $className = $expr->class->toString();

            if (str_ends_with($className, 'Column') || $className === 'PHPNomad\\Database\\Factories\\Column') {
                return $this->parseColumnArgs($expr->args);
            }
        }

        // Factory pattern: (new PrimaryKeyFactory())->toColumn()
        if ($expr instanceof Expr\MethodCall
            && $expr->name instanceof Node\Identifier
            && $expr->name->name === 'toColumn'
            && $expr->var instanceof Expr\New_
            && $expr->var->class instanceof Node\Name
        ) {
            $factoryClass = $expr->var->class->toString();

            // Check known factories
            if (isset(self::FACTORY_COLUMNS[$factoryClass])) {
                return self::FACTORY_COLUMNS[$factoryClass] + ['typeArgs' => null, 'attributes' => []];
            }

            // ForeignKeyFactory: (new ForeignKeyFactory('name', 'refTable', 'refCol'))->toColumn()
            if (str_ends_with($factoryClass, 'ForeignKeyFactory')) {
                return $this->parseForeignKeyFactory($expr->var->args);
            }
        }

        return null;
    }

    /**
     * Parse new Column(name, type, typeArgs, ...attributes) args.
     *
     * @param array<mixed> $args
     * @return ?array{name: string, type: string, typeArgs: ?list<int|float>, attributes: list<string>, factory: ?string}
     */
    protected function parseColumnArgs(array $args): ?array
    {
        if (count($args) < 2) {
            return null;
        }

        $name = $this->extractArgString($args[0] ?? null);
        $type = $this->extractArgString($args[1] ?? null);

        if ($name === null || $type === null) {
            return null;
        }

        $typeArgs = null;
        if (isset($args[2]) && $args[2]->value instanceof Expr\Array_) {
            $typeArgs = [];
            foreach ($args[2]->value->items as $item) {
                if ($item !== null && $item->value instanceof Node\Scalar\Int_) {
                    $typeArgs[] = $item->value->value;
                } elseif ($item !== null && $item->value instanceof Node\Scalar\Float_) {
                    $typeArgs[] = $item->value->value;
                }
            }
        }

        $attributes = [];
        for ($i = 3; $i < count($args); $i++) {
            $attr = $this->extractArgString($args[$i] ?? null);
            if ($attr !== null) {
                $attributes[] = $attr;
            }
        }

        return [
            'name' => $name,
            'type' => $type,
            'typeArgs' => $typeArgs,
            'attributes' => $attributes,
            'factory' => null,
        ];
    }

    /**
     * Parse ForeignKeyFactory constructor args.
     *
     * @param array<mixed> $args
     * @return ?array{name: string, type: string, typeArgs: null, attributes: list<string>, factory: string}
     */
    protected function parseForeignKeyFactory(array $args): ?array
    {
        $name = $this->extractArgString($args[0] ?? null);

        if ($name === null) {
            return null;
        }

        $refTable = $this->extractArgString($args[1] ?? null);
        $refColumn = $this->extractArgString($args[2] ?? null);

        $attributes = [];
        if ($refTable !== null) {
            $attributes[] = "REFERENCES $refTable($refColumn)";
        }

        return [
            'name' => $name,
            'type' => 'BIGINT',
            'typeArgs' => null,
            'attributes' => $attributes,
            'factory' => 'ForeignKeyFactory',
        ];
    }

    /**
     * Find an Array_ node in a method's return statement.
     * Handles: return [...], return $this->method([...]), return array_merge([...], ...)
     */
    protected function findArrayInReturn(Stmt\ClassMethod $method): ?Expr\Array_
    {
        if ($method->stmts === null) {
            return null;
        }

        foreach ($method->stmts as $stmt) {
            if (!$stmt instanceof Stmt\Return_ || $stmt->expr === null) {
                continue;
            }

            // Direct array return
            if ($stmt->expr instanceof Expr\Array_) {
                return $stmt->expr;
            }

            // Method call wrapping an array: $this->mergeTenantColumns([...])
            if ($stmt->expr instanceof Expr\MethodCall && !empty($stmt->expr->args)) {
                $firstArg = $stmt->expr->args[0]->value ?? null;

                if ($firstArg instanceof Expr\Array_) {
                    return $firstArg;
                }
            }

            // Function call wrapping arrays: array_merge([...], [...])
            if ($stmt->expr instanceof Expr\FuncCall && !empty($stmt->expr->args)) {
                $firstArg = $stmt->expr->args[0]->value ?? null;

                if ($firstArg instanceof Expr\Array_) {
                    return $firstArg;
                }
            }
        }

        return null;
    }

    protected function extractArgString(?Node\Arg $arg): ?string
    {
        if ($arg === null) {
            return null;
        }

        if ($arg->value instanceof Node\Scalar\String_) {
            return $arg->value->value;
        }

        return null;
    }

    protected function extractStringReturn(Stmt\Class_ $classNode, string $methodName): ?string
    {
        $method = $classNode->getMethod($methodName);

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
