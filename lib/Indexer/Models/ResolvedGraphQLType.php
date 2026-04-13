<?php

namespace PHPNomad\Cli\Indexer\Models;

final class ResolvedGraphQLType
{
    /**
     * @param string $fqcn
     * @param string $file
     * @param ?string $sdl The GraphQL SDL string from getSdl(), null if couldn't parse
     * @param array<string, array<string, string>> $resolvers Resolver mappings from getResolvers(), e.g. ['Query' => ['content' => 'ContentQueryResolver']]
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $file,
        public readonly ?string $sdl,
        public readonly array $resolvers
    ) {
    }
}
