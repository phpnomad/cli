<?php

namespace {{namespace}};

use {{namespace}}\Interfaces\{{name}}Datastore as {{name}}DatastoreInterface;
use {{namespace}}\Interfaces\{{name}}DatastoreHandler;
use PHPNomad\Datastore\Traits\WithDatastoreDecorator;
use PHPNomad\Datastore\Traits\WithDatastoreCountDecorator;
use PHPNomad\Datastore\Traits\WithDatastorePrimaryKeyDecorator;
use PHPNomad\Datastore\Traits\WithDatastoreWhereDecorator;

class {{className}} implements {{name}}DatastoreInterface
{
    use WithDatastoreDecorator;
    use WithDatastoreCountDecorator;
    use WithDatastorePrimaryKeyDecorator;
    use WithDatastoreWhereDecorator;

    protected {{name}}DatastoreHandler $datastoreHandler;

    public function __construct({{name}}DatastoreHandler $datastoreHandler)
    {
        $this->datastoreHandler = $datastoreHandler;
    }

    // TODO: Implement custom business methods from the interface
}
