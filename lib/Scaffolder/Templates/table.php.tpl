<?php

namespace {{namespace}};

use PHPNomad\Database\Abstracts\Table;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Factories\PrimaryKeyFactory;
use PHPNomad\Database\Factories\DateCreatedFactory;
use PHPNomad\Database\Factories\DateModifiedFactory;

class {{name}} extends Table
{
    public function getUnprefixedName(): string
    {
        return '{{tableName}}';
    }

    public function getColumns(): array
    {
        return [
            (new PrimaryKeyFactory())->toColumn(),
            // TODO: Add columns
            (new DateCreatedFactory())->toColumn(),
            (new DateModifiedFactory())->toColumn(),
        ];
    }
}
