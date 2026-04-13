<?php

namespace PHPNomad\Cli\Indexer\Models;

final class ConstructorParam
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $type,
        public readonly bool $isBuiltin
    ) {
    }
}
