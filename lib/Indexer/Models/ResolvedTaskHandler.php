<?php

namespace PHPNomad\Cli\Indexer\Models;

final class ResolvedTaskHandler
{
    public function __construct(
        public readonly string $handlerFqcn,
        public readonly string $handlerFile,
        public readonly string $taskClass,
        public readonly ?string $taskId,
        public readonly ?string $taskFile,
    ) {
    }
}
