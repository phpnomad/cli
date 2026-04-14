<?php

namespace {{namespace}};

use {{handlerInterface}};
use {{model}};
use {{modelAdapter}};
use {{table}};
use PHPNomad\Database\Abstracts\IdentifiableDatabaseDatastoreHandler;
use PHPNomad\Database\Interfaces\DatabaseServiceProvider;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Database\Traits\WithDatastoreHandlerMethods;

class {{className}} extends IdentifiableDatabaseDatastoreHandler implements {{handlerInterfaceShort}}
{
    use WithDatastoreHandlerMethods;

    public function __construct(
        DatabaseServiceProvider $serviceProvider,
        {{tableShort}} $table,
        {{modelAdapterShort}} $modelAdapter,
        TableSchemaService $tableSchemaService
    ) {
        $this->serviceProvider = $serviceProvider;
        $this->table = $table;
        $this->modelAdapter = $modelAdapter;
        $this->tableSchemaService = $tableSchemaService;
    }
}
