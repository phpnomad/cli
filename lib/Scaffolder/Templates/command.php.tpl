<?php

namespace {{namespace}};

use PHPNomad\Console\Interfaces\Command;
use PHPNomad\Console\Interfaces\Input;
use PHPNomad\Console\Interfaces\OutputStrategy;

class {{name}} implements Command
{
    public function __construct(
        protected OutputStrategy $output
    ) {
    }

    public function getSignature(): string
    {
        return '{{signature}}';
    }

    public function getDescription(): string
    {
        return '{{description}}';
    }

    public function handle(Input $input): int
    {
        // TODO: Implement command logic

        return 0;
    }
}
