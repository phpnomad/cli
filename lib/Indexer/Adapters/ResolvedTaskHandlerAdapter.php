<?php

namespace PHPNomad\Cli\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Models\ResolvedTaskHandler;

class ResolvedTaskHandlerAdapter
{
    public function toArray(ResolvedTaskHandler $handler): array
    {
        return [
            'handlerFqcn' => $handler->handlerFqcn,
            'handlerFile' => $handler->handlerFile,
            'taskClass' => $handler->taskClass,
            'taskId' => $handler->taskId,
            'taskFile' => $handler->taskFile,
        ];
    }

    public function fromArray(array $data): ResolvedTaskHandler
    {
        return new ResolvedTaskHandler(
            handlerFqcn: $data['handlerFqcn'],
            handlerFile: $data['handlerFile'],
            taskClass: $data['taskClass'],
            taskId: $data['taskId'] ?? null,
            taskFile: $data['taskFile'] ?? null,
        );
    }
}
