<?php

namespace PHPNomad\Cli\Indexer\Models;

final class ResolvedController
{
    public function __construct(
        public readonly string $fqcn,
        public readonly string $file,
        public readonly ?string $endpoint,
        public readonly ?string $endpointTail,
        public readonly string $method,
        public readonly bool $usesEndpointBase,
        public readonly bool $hasMiddleware,
        public readonly bool $hasValidations,
        public readonly bool $hasInterceptors
    ) {
    }
}
