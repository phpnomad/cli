<?php

namespace PHPNomad\Cli\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Models\IndexedClass;

final class IndexedClassAdapter
{
    public function __construct(
        protected ConstructorParamAdapter $paramAdapter
    ) {
    }

    public function toArray(IndexedClass $class): array
    {
        return [
            'fqcn' => $class->fqcn,
            'file' => $class->file,
            'line' => $class->line,
            'implements' => $class->implements,
            'traits' => $class->traits,
            'constructorParams' => array_map(fn($p) => $this->paramAdapter->toArray($p), $class->constructorParams),
            'isAbstract' => $class->isAbstract,
            'parentClass' => $class->parentClass,
        ];
    }

    public function fromArray(array $data): IndexedClass
    {
        return new IndexedClass(
            fqcn: $data['fqcn'],
            file: $data['file'],
            line: $data['line'],
            implements: $data['implements'] ?? [],
            traits: $data['traits'] ?? [],
            constructorParams: array_map(fn($p) => $this->paramAdapter->fromArray($p), $data['constructorParams'] ?? []),
            isAbstract: $data['isAbstract'] ?? false,
            parentClass: $data['parentClass'] ?? null
        );
    }
}
