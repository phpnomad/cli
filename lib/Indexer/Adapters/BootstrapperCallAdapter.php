<?php

namespace PHPNomad\Cli\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Models\BootstrapperCall;

final class BootstrapperCallAdapter
{
    public function __construct(
        protected InitializerReferenceAdapter $refAdapter
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(BootstrapperCall $call): array
    {
        return [
            'method' => $call->method,
            'line' => $call->line,
            'initializers' => array_map(fn($r) => $this->refAdapter->toArray($r), $call->initializers),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): BootstrapperCall
    {
        return new BootstrapperCall(
            method: $data['method'] ?? '',
            line: $data['line'] ?? 0,
            initializers: array_map(fn($r) => $this->refAdapter->fromArray($r), $data['initializers'] ?? [])
        );
    }
}
