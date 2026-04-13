<?php

namespace PHPNomad\Cli\Indexer\Models;

final class ResolvedFacade
{
    public function __construct(
        public readonly string $fqcn,
        public readonly string $file,
        public readonly ?string $proxiedInterface,
    ) {
    }
}
