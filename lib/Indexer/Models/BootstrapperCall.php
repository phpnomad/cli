<?php

namespace PHPNomad\Cli\Indexer\Models;

final class BootstrapperCall
{
    /**
     * @param string $method The method where this Bootstrapper was instantiated
     * @param int $line Line number of the new Bootstrapper() call
     * @param InitializerReference[] $initializers Ordered list of Initializer references
     */
    public function __construct(
        public readonly string $method,
        public readonly int $line,
        public readonly array $initializers
    ) {
    }
}
