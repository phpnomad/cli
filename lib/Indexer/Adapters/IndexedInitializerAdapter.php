<?php

namespace PHPNomad\Cli\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Models\IndexedInitializer;

final class IndexedInitializerAdapter
{
    public function __construct(
        protected IndexedBindingAdapter $bindingAdapter
    ) {
    }

    public function toArray(IndexedInitializer $init): array
    {
        return [
            'fqcn' => $init->fqcn,
            'file' => $init->file,
            'isVendor' => $init->isVendor,
            'implementedInterfaces' => $init->implementedInterfaces,
            'classDefinitions' => array_map(fn($b) => $this->bindingAdapter->toArray($b), $init->classDefinitions),
            'controllers' => $init->controllers,
            'listeners' => $init->listeners,
            'eventBindings' => $init->eventBindings,
            'commands' => $init->commands,
            'mutations' => $init->mutations,
            'taskHandlers' => $init->taskHandlers,
            'typeDefinitions' => $init->typeDefinitions,
            'updates' => $init->updates,
            'facades' => $init->facades,
            'hasLoadCondition' => $init->hasLoadCondition,
            'isLoadable' => $init->isLoadable,
        ];
    }

    public function fromArray(array $data): IndexedInitializer
    {
        return new IndexedInitializer(
            fqcn: $data['fqcn'],
            file: $data['file'] ?? '',
            isVendor: $data['isVendor'] ?? false,
            implementedInterfaces: $data['implementedInterfaces'] ?? [],
            classDefinitions: array_map(fn($b) => $this->bindingAdapter->fromArray($b), $data['classDefinitions'] ?? []),
            controllers: $data['controllers'] ?? [],
            listeners: $data['listeners'] ?? [],
            eventBindings: $data['eventBindings'] ?? [],
            commands: $data['commands'] ?? [],
            mutations: $data['mutations'] ?? [],
            taskHandlers: $data['taskHandlers'] ?? [],
            typeDefinitions: $data['typeDefinitions'] ?? [],
            updates: $data['updates'] ?? [],
            facades: $data['facades'] ?? [],
            hasLoadCondition: $data['hasLoadCondition'] ?? false,
            isLoadable: $data['isLoadable'] ?? false
        );
    }
}
