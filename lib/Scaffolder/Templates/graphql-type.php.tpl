<?php

namespace {{namespace}};

use PHPNomad\GraphQL\Interfaces\TypeDefinition;

class {{name}} implements TypeDefinition
{
    public function getSdl(): string
    {
        return <<<'GQL'
type {{typeName}} {
    id: ID!
}

extend type Query {
    {{typeNameLower}}(id: ID!): {{typeName}}
}
GQL;
    }

    public function getResolvers(): array
    {
        return [
            // TODO: Add field resolvers
            // 'Query' => ['{{typeNameLower}}' => YourResolver::class],
        ];
    }
}
