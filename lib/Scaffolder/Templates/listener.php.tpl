<?php

namespace {{namespace}};

use PHPNomad\Events\Interfaces\CanHandle;
use PHPNomad\Events\Interfaces\Event;
use {{event}};

class {{name}} implements CanHandle
{
    public function __construct(
        // TODO: Add constructor dependencies
    ) {
    }

    public function handle(Event $event): void
    {
        // TODO: Implement listener logic
    }
}
