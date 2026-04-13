<?php

namespace PHPNomad\Cli\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Models\ResolvedController;

final class ResolvedControllerAdapter
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(ResolvedController $controller): array
    {
        return [
            'fqcn' => $controller->fqcn,
            'file' => $controller->file,
            'endpoint' => $controller->endpoint,
            'endpointTail' => $controller->endpointTail,
            'method' => $controller->method,
            'usesEndpointBase' => $controller->usesEndpointBase,
            'hasMiddleware' => $controller->hasMiddleware,
            'hasValidations' => $controller->hasValidations,
            'hasInterceptors' => $controller->hasInterceptors,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): ResolvedController
    {
        return new ResolvedController(
            fqcn: $data['fqcn'],
            file: $data['file'] ?? '',
            endpoint: $data['endpoint'] ?? null,
            endpointTail: $data['endpointTail'] ?? null,
            method: $data['method'] ?? 'UNKNOWN',
            usesEndpointBase: $data['usesEndpointBase'] ?? false,
            hasMiddleware: $data['hasMiddleware'] ?? false,
            hasValidations: $data['hasValidations'] ?? false,
            hasInterceptors: $data['hasInterceptors'] ?? false
        );
    }
}
