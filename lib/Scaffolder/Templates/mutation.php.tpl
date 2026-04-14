<?php

namespace {{namespace}};

use PHPNomad\Mutator\Interfaces\MutatorHandler;
use PHPNomad\Mutator\Interfaces\MutationAdapter;
use PHPNomad\Mutator\Traits\CanMutateFromAdapter;

class {{name}} implements MutatorHandler
{
    use CanMutateFromAdapter;

    public function __construct(
        protected MutationAdapter $mutationAdapter
    ) {
    }
}
