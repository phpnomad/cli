<?php

namespace PHPNomad\Cli\Indexer\Models;

final class IndexedApplication
{
    /**
     * @param string $fqcn
     * @param string $file
     * @param IndexedBinding[] $preBootstrapBindings
     * @param BootstrapperCall[] $bootstrapperCalls
     * @param IndexedBinding[] $postBootstrapBindings
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $file,
        public readonly array $preBootstrapBindings,
        public readonly array $bootstrapperCalls,
        public readonly array $postBootstrapBindings
    ) {
    }

    /**
     * @return InitializerReference[]
     */
    public function getAllInitializerReferences(): array
    {
        $refs = [];

        foreach ($this->bootstrapperCalls as $call) {
            foreach ($call->initializers as $ref) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }
}
