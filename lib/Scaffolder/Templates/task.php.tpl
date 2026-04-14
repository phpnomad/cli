<?php

namespace {{namespace}};

use PHPNomad\Tasks\Interfaces\Task;

class {{name}} implements Task
{
    public function __construct(
        // TODO: Add task properties
    ) {
    }

    public static function getId(): string
    {
        return '{{taskId}}';
    }

    public function toPayload(): array
    {
        // TODO: Serialize task properties to array
        return [];
    }

    public static function fromPayload(array $payload): static
    {
        // TODO: Deserialize from payload array
        return new static();
    }
}
