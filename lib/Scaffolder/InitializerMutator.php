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

        // Clone AST for modification (needed for key collision and create-method paths)
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new CloningVisitor());
        $newStmts = $traverser->traverse($oldStmts);

        // Resolve names on a separate traversal to get FQCNs
        $resolverTraverser = new NodeTraverser();
        $resolverTraverser->addVisitor(new NameResolver());
        $resolverTraverser->traverse($oldStmts);

        $nodeFinder = new NodeFinder();

        $clonedClasses = $nodeFinder->findInstanceOf($newStmts, Stmt\Class_::class);

        if (empty($clonedClasses)) {
            return new MutationResult(false, "No class found in: $filePath");
        }

        /** @var Stmt\Class_ $classNode */
        $classNode = $clonedClasses[0];

        // Check if the method exists
        $method = $this->findMethod($classNode, $registration->method);

        if ($method !== null) {
            return $this->handleExistingMethod($code, $method, $registration, $filePath, $newStmts, $oldStmts, $oldTokens);
        }

        return $this->handleNewMethod($code, $classNode, $registration, $filePath, $newStmts, $oldStmts, $oldTokens);
    }

    protected function handleExistingMethod(
        string $code,
        Stmt\ClassMethod $method,
        RecipeRegistration $registration,
        string $filePath,
        array $newStmts,
        array $oldStmts,
        array $oldTokens
    ): MutationResult {
        $returnArray = $this->findReturnArray($method);

        if ($returnArray === null) {
            $entry = $this->formatManualEntry($registration);
            return new MutationResult(
                false,
                "Could not auto-register in {$registration->method}(). The return statement is not a simple array literal.",
                $entry
            );
        }

        if ($this->isDuplicate($returnArray, $registration)) {
            return new MutationResult(true, "Already registered in {$registration->method}()");
        }

        // Key collision: existing map key needs value converted to array — use AST for this rare case
        if ($registration->type === 'map' && $this->hasExistingKey($returnArray, $registration->key ?? '')) {
            $this->appendMapEntry($returnArray, $registration->key ?? '', $registration->value ?? '');

            $printer = new PrettyPrinter\Standard();
            $newCode = $printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);

            file_put_contents($filePath, $newCode);

            return new MutationResult(true, "Registered in {$registration->method}()");
        }

        // Normal append — string-based insertion for proper formatting
        $entryStr = $this->formatEntryString($registration);
        $newCode = $this->insertArrayEntry($code, $returnArray, $entryStr);

        file_put_contents($filePath, $newCode);

        return new MutationResult(true, "Registered in {$registration->method}()");
    }

    /**
     * @param Node[] $newStmts
     * @param Node[] $oldStmts
     * @param array<mixed> $oldTokens
     */
    protected function handleNewMethod(
        string $code,
        Stmt\Class_ $classNode,
        RecipeRegistration $registration,
        string $filePath,
        array &$newStmts,
        array $oldStmts,
        array $oldTokens
    ): MutationResult {
        // Add interface and use statement via AST (needs format-preserving print)
        $this->addInterfaceAndUseStatement($classNode, $registration, $newStmts);

        $printer = new PrettyPrinter\Standard();
        $intermediateCode = $printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);

        // Insert method as a properly formatted string
        $methodStr = $this->buildFormattedMethod($registration, $intermediateCode);
        $newCode = $this->insertMethodBeforeClassEnd($intermediateCode, $methodStr);

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
                $keyVal = $this->extractKeyValue($item->key);
                $valueFqcn = $this->extractClassConstFqcn($item->value);

                if ($keyVal === $registration->key && $valueFqcn === $registration->value) {
                    return true;
                }

                // Check for duplicate in nested array values
                if ($keyVal === $registration->key && $item->value instanceof Expr\Array_) {
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

    protected function hasExistingKey(Expr\Array_ $array, string $key): bool
    {
        foreach ($array->items as $item) {
            if ($item === null || $item->key === null) {
                continue;
            }
            if ($this->extractKeyValue($item->key) === $key) {
                return true;
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

    /**
     * Extract either a ::class FQCN or a string literal value from an expression.
     */
    protected function extractKeyValue(Node\Expr $expr): ?string
    {
        $fqcn = $this->extractClassConstFqcn($expr);

        if ($fqcn !== null) {
            return $fqcn;
        }

        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        return null;
    }

    /**
     * Determine if a value looks like a FQCN (contains backslash) or a plain string.
     */
    protected function isFqcn(string $value): bool
    {
        return str_contains($value, '\\');
    }

    /**
     * Format a registration entry as a PHP source string (e.g., \App\Foo::class => \App\Bar::class).
     */
    protected function formatEntryString(RecipeRegistration $registration): string
    {
        $value = $this->formatClassReference($registration->value ?? '');

        if ($registration->type === 'list') {
            return $value;
        }

        $key = $this->formatClassReference($registration->key ?? '');

        return "$key => $value";
    }

    protected function formatClassReference(string $value): string
    {
        if ($this->isFqcn($value)) {
            return '\\' . ltrim($value, '\\') . '::class';
        }

        return "'" . addslashes($value) . "'";
    }

    /**
     * Insert a formatted array entry into the source code before the array's closing bracket.
     */
    protected function insertArrayEntry(string $code, Expr\Array_ $array, string $entryString): string
    {
        $closingBracketPos = $array->getEndFilePos();

        $beforeBracket = substr($code, 0, $closingBracketPos);
        $fromBracket = substr($code, $closingBracketPos);

        $indent = $this->detectArrayItemIndentation($code, $array);

        // Strip trailing whitespace before ']' but preserve it (contains ']' indentation)
        $trimmed = rtrim($beforeBracket);
        $trailingWhitespace = substr($beforeBracket, strlen($trimmed));

        // Ensure trailing comma after last entry
        if (!str_ends_with($trimmed, ',') && !str_ends_with($trimmed, '[')) {
            $trimmed .= ',';
        }

        return $trimmed . "\n" . $indent . $entryString . "," . $trailingWhitespace . $fromBracket;
    }

    protected function detectArrayItemIndentation(string $code, Expr\Array_ $array): string
    {
        if (!empty($array->items) && $array->items[0] !== null) {
            $itemPos = $array->items[0]->getStartFilePos();
            $lineStart = strrpos(substr($code, 0, $itemPos), "\n");

            if ($lineStart !== false) {
                return substr($code, $lineStart + 1, $itemPos - $lineStart - 1);
            }
        }

        // Fallback: use array's opening bracket indentation + 4 spaces
        $arrayPos = $array->getStartFilePos();
        $lineStart = strrpos(substr($code, 0, $arrayPos), "\n");

        if ($lineStart !== false) {
            $bracketIndent = $arrayPos - $lineStart - 1;
            return str_repeat(' ', $bracketIndent + 4);
        }

        return '            ';
    }

    /**
     * Build a properly formatted method body string for insertion into a class.
     */
    protected function buildFormattedMethod(RecipeRegistration $registration, string $code): string
    {
        $indent = $this->detectClassBodyIndentation($code);
        $bodyIndent = $indent . '    ';
        $itemIndent = $bodyIndent . '    ';

        $entryStr = $this->formatEntryString($registration);

        return "\n{$indent}public function {$registration->method}(): array\n"
            . "{$indent}{\n"
            . "{$bodyIndent}return [\n"
            . "{$itemIndent}{$entryStr},\n"
            . "{$bodyIndent}];\n"
            . "{$indent}}";
    }

    protected function detectClassBodyIndentation(string $code): string
    {
        if (preg_match('/^( +)(?:public|protected|private) function /m', $code, $matches)) {
            return $matches[1];
        }

        return '    ';
    }

    /**
     * Insert a method string before the class closing brace.
     */
    protected function insertMethodBeforeClassEnd(string $code, string $methodString): string
    {
        $lastBrace = strrpos($code, '}');

        if ($lastBrace === false) {
            return $code;
        }

        return substr($code, 0, $lastBrace) . $methodString . "\n" . substr($code, $lastBrace);
    }

    protected function addInterfaceAndUseStatement(Stmt\Class_ $classNode, RecipeRegistration $registration, array &$stmts): void
    {
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
    }

    /**
     * Append a value to an existing map key's array (key collision case).
     */
    protected function appendMapEntry(Expr\Array_ $array, string $key, string $value): void
    {
        foreach ($array->items as $item) {
            if ($item === null || $item->key === null) {
                continue;
            }

            $keyVal = $this->extractKeyValue($item->key);

            if ($keyVal === $key) {
                if ($item->value instanceof Expr\Array_) {
                    $item->value->items[] = new Expr\ArrayItem(
                        $this->makeClassConstFetch($value)
                    );
                } else {
                    $item->value = new Expr\Array_([
                        new Expr\ArrayItem($item->value),
                        new Expr\ArrayItem($this->makeClassConstFetch($value)),
                    ]);
                }

                return;
            }
        }
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
