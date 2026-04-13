<?php

namespace PHPNomad\Cli\Indexer\Models;

final class IndexedClass
{
    /**
     * @param string $fqcn
     * @param string $file
     * @param int $line
     * @param string[] $implements
     * @param string[] $traits
     * @param ConstructorParam[] $constructorParams
     * @param bool $isAbstract
     * @param ?string $parentClass
     * @param ?string $description First line of the class PHPDoc comment
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $file,
        public readonly int $line,
        public readonly array $implements,
        public readonly array $traits,
        public readonly array $constructorParams,
        public readonly bool $isAbstract,
        public readonly ?string $parentClass,
        public readonly ?string $description
    ) {
    }
}
