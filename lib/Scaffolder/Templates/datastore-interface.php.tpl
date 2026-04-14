<?php

namespace {{namespace}};

use PHPNomad\Datastore\Interfaces\Datastore;
use PHPNomad\Datastore\Interfaces\DatastoreHasCounts;
use PHPNomad\Datastore\Interfaces\DatastoreHasPrimaryKey;
use PHPNomad\Datastore\Interfaces\DatastoreHasWhere;

interface {{className}} extends Datastore, DatastoreHasPrimaryKey, DatastoreHasWhere, DatastoreHasCounts
{
    // TODO: Add custom business methods
}
