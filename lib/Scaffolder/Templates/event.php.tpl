<?php

namespace {{namespace}};

use PHPNomad\Events\Interfaces\Event;

class {{name}} implements Event
{
    public function __construct(
        // TODO: Add event properties
    ) {
    }

    public static function getId(): string
    {
        return '{{eventId}}';
    }
}
