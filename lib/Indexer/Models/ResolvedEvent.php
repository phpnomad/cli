<?php

namespace PHPNomad\Cli\Indexer\Models;

final class ResolvedEvent
{
    /**
     * @param string $fqcn
     * @param string $file
     * @param ?string $eventId From static getId(), null if couldn't parse
     * @param array $properties Array of ['name' => string, 'type' => ?string] from constructor params
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $file,
        public readonly ?string $eventId,
        public readonly array $properties
    ) {
    }
}
