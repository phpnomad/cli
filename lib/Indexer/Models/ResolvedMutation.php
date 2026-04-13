<?php

namespace PHPNomad\Cli\Indexer\Models;

final class ResolvedMutation
{
    /**
     * @param list<string> $actions
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $file,
        public readonly array $actions,
        public readonly bool $usesAdapter,
        public readonly ?string $adapterClass,
    ) {
    }
}
