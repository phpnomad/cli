<?php

namespace {{namespace}};

use PHPNomad\Di\Interfaces\CanSetContainer;
use PHPNomad\Di\Traits\HasSettableContainer;
use PHPNomad\Loader\Interfaces\HasClassDefinitions;

class {{name}} implements CanSetContainer, HasClassDefinitions
{
    use HasSettableContainer;

    public function getClassDefinitions(): array
    {
        return [
            // TODO: Add DI bindings
            // ConcreteClass::class => InterfaceClass::class,
        ];
    }
}
