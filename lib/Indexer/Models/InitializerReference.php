<?php

namespace PHPNomad\Cli\Indexer\Models;

final class InitializerReference
{
    /**
     * @param ?string $fqcn Null if dynamic/unresolvable
     * @param string $source How this reference was discovered: "inline", method name, or variable name
     * @param bool $isDynamic Whether this reference could not be statically resolved
     */
    public function __construct(
        public readonly ?string $fqcn,
        public readonly string $source,
        public readonly bool $isDynamic
    ) {
    }
}
