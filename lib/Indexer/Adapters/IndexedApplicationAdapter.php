<?php

namespace PHPNomad\Cli\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Models\IndexedApplication;

final class IndexedApplicationAdapter
{
    public function __construct(
        protected IndexedBindingAdapter $bindingAdapter,
        protected BootstrapperCallAdapter $callAdapter
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(IndexedApplication $app): array
    {
        return [
            'fqcn' => $app->fqcn,
            'file' => $app->file,
            'preBootstrapBindings' => array_map(fn($b) => $this->bindingAdapter->toArray($b), $app->preBootstrapBindings),
            'bootstrapperCalls' => array_map(fn($c) => $this->callAdapter->toArray($c), $app->bootstrapperCalls),
            'postBootstrapBindings' => array_map(fn($b) => $this->bindingAdapter->toArray($b), $app->postBootstrapBindings),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): IndexedApplication
    {
        return new IndexedApplication(
            fqcn: $data['fqcn'],
            file: $data['file'] ?? '',
            preBootstrapBindings: array_map(fn($b) => $this->bindingAdapter->fromArray($b), $data['preBootstrapBindings'] ?? []),
            bootstrapperCalls: array_map(fn($c) => $this->callAdapter->fromArray($c), $data['bootstrapperCalls'] ?? []),
            postBootstrapBindings: array_map(fn($b) => $this->bindingAdapter->fromArray($b), $data['postBootstrapBindings'] ?? [])
        );
    }
}
