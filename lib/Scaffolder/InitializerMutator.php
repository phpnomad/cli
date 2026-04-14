<?php

namespace PHPNomad\Cli\Scaffolder;

use PHPNomad\Cli\Scaffolder\Models\MutationResult;
use PHPNomad\Cli\Scaffolder\Models\RecipeRegistration;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

class InitializerMutator
{
    public function mutate(string $filePath, RecipeRegistration $registration): MutationResult
    {
        if (!file_exists($filePath)) {
            return new MutationResult(false, "File not found: $filePath");
        }

        $code = file_get_contents($filePath);

        if ($code === false) {
            return new MutationResult(false, "Could not read: $filePath");
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $oldStmts = $parser->parse($code);
        } catch (\Throwable $e) {
            return new MutationResult(false, "Parse error in $filePath: " . $e->getMessage());
        }

        if ($oldStmts === null) {
            return new MutationResult(false, "Could not parse: $filePath");
        }

        $oldTokens = $parser->getTokens();

        // Clone AST for modification
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new CloningVisitor());
        $newStmts = $traverser->traverse($oldStmts);

        // Resolve names on a separate traversal to get FQCNs
        $resolverTraverser = new NodeTraverser();
        $resolverTraverser->addVisitor(new NameResolver());
        $resolvedStmts = $resolverTraverser->traverse($oldStmts);

        $nodeFinder = new NodeFinder();

        // Find class node in both resolved (for reading) and cloned (for modification)
        $resolvedClasses = $nodeFinder->findInstanceOf($resolvedStmts, Stmt\Class_::class);
        $clonedClasses = $nodeFinder->findInstanceOf($newStmts, Stmt\Class_::class);

        if (empty($resolvedClasses) || empty($clonedClasses)) {
            return new MutationResult(false, "No class found in: $filePath");
        }

        /** @var Stmt\Class_ $resolvedClass */
        $resolvedClass = $resolvedClasses[0];
        /** @var Stmt\Class_ $classNode */
        $classNode = $clonedClasses[0];

        // Check if the method exists
        $method = $this->findMethod($classNode, $registration->method);

        if ($method !== null) {
            $result = $this->appendToExistingMethod($method, $registration);

            if (!$result->success) {
                return $result;
            }

            // If already registered, no file change needed
            if (str_contains($result->message, 'Already')) {
                return $result;
            }
        } else {
            $this->createMethodWithEntry($classNode, $registration, $newStmts);
        }

        // Print with format preservation
        $printer = new PrettyPrinter\Standard();
        $newCode = $printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);

        file_put_contents($filePath, $newCode);

        return new MutationResult(true, "Registered in {$registration->method}()");
    }

    protected function findMethod(Stmt\Class_ $classNode, string $methodName): ?Stmt\ClassMethod
    {
        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\ClassMethod && $stmt->name->toString() === $methodName) {
                return $stmt;
            }
        }

        return null;
    }

    protected function appendToExistingMethod(Stmt\ClassMethod $method, RecipeRegistration $registration): MutationResult
    {
        $returnArray = $this->findReturnArray($method);

        if ($returnArray === null) {
            $entry = $this->formatManualEntry($registration);
            return new MutationResult(
                false,
                "Could not auto-register in {$registration->method}(). The return statement is not a simple array literal.",
                $entry
            );
        }

        // Check for duplicate
        if ($this->isDuplicate($returnArray, $registration)) {
            return new MutationResult(true, "Already registered in {$registration->method}()");
        }

        if ($registration->type === 'list') {
            $this->appendListEntry($returnArray, $registration->value ?? '');
        } else {
            $this->appendMapEntry($returnArray, $registration->key ?? '', $registration->value ?? '');
        }

        return new MutationResult(true, "Registered in {$registration->method}()");
    }

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

    protected function isDuplicate(Expr\Array_ $array, RecipeRegistration $registration): bool
    {
        if ($registration->type === 'list') {
            foreach ($array->items as $item) {
                if ($item === null) {
                    continue;
                }
                $fqcn = $this->extractClassConstFqcn($item->value);
                if ($fqcn === $registration->value) {
                    return true;
                }
            }
        } else {
            foreach ($array->items as $item) {
                if ($item === null || $item->key === null) {
                    continue;
                }
                $keyFqcn = $this->extractClassConstFqcn($item->key);
                $valueFqcn = $this->extractClassConstFqcn($item->value);

                if ($keyFqcn === $registration->key && $valueFqcn === $registration->value) {
                    return true;
                }

                // Check for duplicate in nested array values
                if ($keyFqcn === $registration->key && $item->value instanceof Expr\Array_) {
                    foreach ($item->value->items as $subItem) {
                        if ($subItem !== null && $this->extractClassConstFqcn($subItem->value) === $registration->value) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    protected function extractClassConstFqcn(Node\Expr $expr): ?string
    {
        if ($expr instanceof Expr\ClassConstFetch
            && $expr->name instanceof Identifier
            && $expr->name->name === 'class'
        ) {
            if ($expr->class instanceof Name\FullyQualified) {
                return $expr->class->toString();
            }
            if ($expr->class instanceof Name) {
                return $expr->class->toString();
            }
        }

        return null;
    }

    protected function appendListEntry(Expr\Array_ $array, string $fqcn): void
    {
        $array->items[] = new Expr\ArrayItem(
            $this->makeClassConstFetch($fqcn)
        );
    }

    protected function appendMapEntry(Expr\Array_ $array, string $key, string $value): void
    {
        // Check if key already exists — if so, append to its value array
        foreach ($array->items as $item) {
            if ($item === null || $item->key === null) {
                continue;
            }

            $keyFqcn = $this->extractClassConstFqcn($item->key);

            if ($keyFqcn === $key) {
                // Key exists — convert to array if needed and append
                if ($item->value instanceof Expr\Array_) {
                    $item->value->items[] = new Expr\ArrayItem(
                        $this->makeClassConstFetch($value)
                    );
                } else {
                    // Convert single value to array
                    $item->value = new Expr\Array_([
                        new Expr\ArrayItem($item->value),
                        new Expr\ArrayItem($this->makeClassConstFetch($value)),
                    ]);
                }

                return;
            }
        }

        // Key doesn't exist — add new entry
        $array->items[] = new Expr\ArrayItem(
            $this->makeClassConstFetch($value),
            $this->makeClassConstFetch($key)
        );
    }

    /**
     * @param Node[] $stmts
     */
    protected function createMethodWithEntry(Stmt\Class_ $classNode, RecipeRegistration $registration, array &$stmts): void
    {
        // Add interface to implements list
        $interfaceName = new Name\FullyQualified($registration->interface);
        $alreadyImplements = false;

        foreach ($classNode->implements as $impl) {
            if ($impl->toString() === $registration->interface) {
                $alreadyImplements = true;
                break;
            }
        }

        if (!$alreadyImplements) {
            $classNode->implements[] = $interfaceName;
            $this->addUseStatement($stmts, $registration->interface);
        }

        // Build the return array
        if ($registration->type === 'list') {
            $items = [
                new Expr\ArrayItem($this->makeClassConstFetch($registration->value ?? '')),
            ];
        } else {
            $items = [
                new Expr\ArrayItem(
                    $this->makeClassConstFetch($registration->value ?? ''),
                    $this->makeClassConstFetch($registration->key ?? '')
                ),
            ];
        }

        // Create the method
        $method = new Stmt\ClassMethod(
            new Identifier($registration->method),
            [
                'flags' => Stmt\Class_::MODIFIER_PUBLIC,
                'returnType' => new Identifier('array'),
                'stmts' => [
                    new Stmt\Return_(new Expr\Array_($items, ['kind' => Expr\Array_::KIND_SHORT])),
                ],
            ]
        );

        $classNode->stmts[] = $method;
    }

    /**
     * @param Node[] $stmts
     */
    protected function addUseStatement(array &$stmts, string $fqcn): void
    {
        // Find the namespace node
        $namespaceNode = null;

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                $namespaceNode = $stmt;
                break;
            }
        }

        $targetStmts = $namespaceNode !== null ? $namespaceNode->stmts : $stmts;

        // Check if use statement already exists
        foreach ($targetStmts as $stmt) {
            if ($stmt instanceof Stmt\Use_) {
                foreach ($stmt->uses as $use) {
                    if ($use->name->toString() === $fqcn) {
                        return;
                    }
                }
            }
        }

        // Find insertion point (after last use statement, or after namespace declaration)
        $insertIndex = 0;

        foreach ($targetStmts as $i => $stmt) {
            if ($stmt instanceof Stmt\Use_) {
                $insertIndex = $i + 1;
            }
        }

        // If no use statements found, insert after opening (skip declare/namespace)
        if ($insertIndex === 0) {
            foreach ($targetStmts as $i => $stmt) {
                if (!$stmt instanceof Stmt\Declare_ && !$stmt instanceof Stmt\Namespace_) {
                    $insertIndex = $i;
                    break;
                }
            }
        }

        $useStmt = new Stmt\Use_([
            new Node\UseItem(new Name($fqcn)),
        ]);

        if ($namespaceNode !== null) {
            array_splice($namespaceNode->stmts, $insertIndex, 0, [$useStmt]);
        } else {
            array_splice($stmts, $insertIndex, 0, [$useStmt]);
        }
    }

    protected function makeClassConstFetch(string $fqcn): Expr\ClassConstFetch
    {
        return new Expr\ClassConstFetch(
            new Name\FullyQualified($fqcn),
            'class'
        );
    }

    protected function formatManualEntry(RecipeRegistration $registration): string
    {
        if ($registration->type === 'list') {
            return $registration->value . '::class';
        }

        return $registration->key . '::class => ' . $registration->value . '::class';
    }
}
