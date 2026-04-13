<?php

namespace PHPNomad\Cli\Indexer;

use PHPNomad\Cli\Indexer\Models\ConstructorParam;
use PHPNomad\Cli\Indexer\Models\IndexedClass;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

class ClassIndex
{
    /** @var array<string, IndexedClass> */
    protected array $vendorCache = [];

    /**
     * Scan PHP files in a directory and build a class index.
     *
     * @return array<string, IndexedClass> Keyed by FQCN
     */
    public function build(string $path, bool $includeVendor = false): array
    {
        $finder = new Finder();
        $finder->files()->name('*.php')->in($path);

        if (!$includeVendor) {
            $finder->exclude(['vendor', 'tests', 'node_modules']);
        } else {
            $finder->exclude(['tests', 'node_modules']);
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $index = [];

        foreach ($finder as $file) {
            $relativePath = $this->relativePath($path, $file->getRealPath());
            $results = $this->parseFile($parser, $file->getContents(), $relativePath);

            foreach ($results as $class) {
                $index[$class->fqcn] = $class;
            }
        }

        return $index;
    }

    /**
     * Resolve a single class from vendor using Composer's autoload classmap or PSR-4 map.
     */
    public function resolveFromVendor(string $fqcn, string $basePath): ?IndexedClass
    {
        if (isset($this->vendorCache[$fqcn])) {
            return $this->vendorCache[$fqcn];
        }

        $basePath = rtrim($basePath, '/');
        $filePath = $this->resolveVendorFilePath($fqcn, $basePath);

        if ($filePath === null || !file_exists($filePath)) {
            return null;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $relativePath = $this->relativePath($basePath, $filePath);
        $results = $this->parseFile($parser, file_get_contents($filePath), $relativePath);

        foreach ($results as $class) {
            $this->vendorCache[$class->fqcn] = $class;

            if ($class->fqcn === $fqcn) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Try to find a file path for a class using classmap first, then PSR-4.
     */
    protected function resolveVendorFilePath(string $fqcn, string $basePath): ?string
    {
        // Try classmap first
        $classmapFile = $basePath . '/vendor/composer/autoload_classmap.php';

        if (file_exists($classmapFile)) {
            $classmap = require $classmapFile;

            if (isset($classmap[$fqcn])) {
                return $classmap[$fqcn];
            }
        }

        // Fall back to PSR-4
        $psr4File = $basePath . '/vendor/composer/autoload_psr4.php';

        if (!file_exists($psr4File)) {
            return null;
        }

        $psr4 = require $psr4File;

        foreach ($psr4 as $namespace => $dirs) {
            $namespace = rtrim($namespace, '\\');

            if (!str_starts_with($fqcn, $namespace . '\\')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($fqcn, strlen($namespace) + 1)) . '.php';

            foreach ($dirs as $dir) {
                $file = rtrim($dir, '/') . '/' . $relative;

                if (file_exists($file)) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * Parse a PHP file and extract all class declarations.
     *
     * @return IndexedClass[]
     */
    protected function parseFile($parser, string $code, string $relativePath): array
    {
        try {
            $ast = $parser->parse($code);
        } catch (\Throwable $e) {
            return [];
        }

        if ($ast === null) {
            return [];
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);

        $nodeFinder = new NodeFinder();
        $classes = $nodeFinder->findInstanceOf($ast, Stmt\Class_::class);
        $results = [];

        foreach ($classes as $classNode) {
            $result = $this->indexClassNode($classNode, $relativePath);

            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    protected function indexClassNode(Stmt\Class_ $classNode, string $file): ?IndexedClass
    {
        if ($classNode->namespacedName === null) {
            return null;
        }

        $fqcn = $classNode->namespacedName->toString();

        $implements = [];
        foreach ($classNode->implements as $interface) {
            $implements[] = $interface->toString();
        }

        $traits = [];
        foreach ($classNode->getTraitUses() as $traitUse) {
            foreach ($traitUse->traits as $trait) {
                $traits[] = $trait->toString();
            }
        }

        $constructorParams = [];
        $constructor = $classNode->getMethod('__construct');

        if ($constructor !== null) {
            foreach ($constructor->params as $param) {
                $type = null;
                $isBuiltin = true;

                if ($param->type !== null) {
                    if ($param->type instanceof Node\Name) {
                        $type = $param->type->toString();
                        $isBuiltin = false;
                    } elseif ($param->type instanceof Node\Identifier) {
                        $type = $param->type->name;
                        $isBuiltin = true;
                    }
                }

                $constructorParams[] = new ConstructorParam(
                    $param->var->name,
                    $type,
                    $isBuiltin
                );
            }
        }

        $parentClass = null;

        if ($classNode->extends !== null) {
            $parentClass = $classNode->extends->toString();
        }

        $description = $this->extractDescription($classNode);

        return new IndexedClass(
            $fqcn,
            $file,
            $classNode->getStartLine(),
            $implements,
            $traits,
            $constructorParams,
            $classNode->isAbstract(),
            $parentClass,
            $description
        );
    }

    protected function extractDescription(Stmt\Class_ $classNode): ?string
    {
        $docComment = $classNode->getDocComment();

        if ($docComment === null) {
            return null;
        }

        $lines = explode("\n", $docComment->getText());

        foreach ($lines as $line) {
            $cleaned = trim(preg_replace('/^[\s\/*]+/', '', $line));

            if ($cleaned !== '' && !str_starts_with($cleaned, '@')) {
                return $cleaned;
            }
        }

        return null;
    }

    protected function relativePath(string $basePath, string $absolutePath): string
    {
        $basePath = rtrim(realpath($basePath) ?: $basePath, '/') . '/';
        $absolutePath = realpath($absolutePath) ?: $absolutePath;

        if (str_starts_with($absolutePath, $basePath)) {
            return substr($absolutePath, strlen($basePath));
        }

        return $absolutePath;
    }
}
