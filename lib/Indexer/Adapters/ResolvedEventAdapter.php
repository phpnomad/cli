<?php

namespace PHPNomad\Cli\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Models\ResolvedEvent;

final class ResolvedEventAdapter
{
    public function toArray(ResolvedEvent $event): array
    {
        return [
            'fqcn' => $event->fqcn,
            'file' => $event->file,
            'eventId' => $event->eventId,
            'properties' => $event->properties,
        ];
    }

    public function fromArray(array $data): ResolvedEvent
    {
        return new ResolvedEvent(
            fqcn: $data['fqcn'],
            file: $data['file'] ?? '',
            eventId: $data['eventId'] ?? null,
            properties: $data['properties'] ?? []
        );
    }
}
