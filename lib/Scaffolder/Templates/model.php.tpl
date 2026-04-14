<?php

namespace {{namespace}};

use PHPNomad\Datamodel\Interfaces\DataModel;
use PHPNomad\Datamodel\Interfaces\HasSingleIntIdentity;
use PHPNomad\Datamodel\Traits\WithSingleIntIdentity;

class {{name}} implements DataModel, HasSingleIntIdentity
{
    use WithSingleIntIdentity;

    public function __construct(
        int $id,
        // TODO: Add model properties
    ) {
        $this->id = $id;
    }
}
