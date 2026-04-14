<?php

namespace {{namespace}};

use PHPNomad\Facade\Abstracts\Facade;
use {{interface}};

class {{name}} extends Facade
{
    protected function abstractInstance(): string
    {
        return \{{interface}}::class;
    }
}
