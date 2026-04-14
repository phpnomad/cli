<?php

namespace PHPNomad\Cli\Scaffolder;

use PHPNomad\Cli\Scaffolder\Models\Recipe;
use PHPNomad\Cli\Scaffolder\Models\RecipeFile;
use PHPNomad\Cli\Scaffolder\Models\RecipeRegistration;
use PHPNomad\Cli\Scaffolder\Models\RecipeRequirement;
use PHPNomad\Cli\Scaffolder\Models\RecipeVar;
use RuntimeException;

class RecipeLoader
{
    public function load(string $from): Recipe
    {
        $path = $this->resolvePath($from);

        if (!file_exists($path)) {
            throw new RuntimeException("Recipe not found: $from (looked at $path)");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Could not read recipe file: $path");
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            throw new RuntimeException("Invalid JSON in recipe file: $path");
        }

        return $this->parse($data);
    }

    protected function resolvePath(string $from): string
    {
        if (str_contains($from, '/') || str_ends_with($from, '.json')) {
            return $from;
        }

        return __DIR__ . '/Recipes/' . $from . '.json';
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function parse(array $data): Recipe
    {
        if (!isset($data['name'])) {
            throw new RuntimeException('Recipe must have a "name" field');
        }

        $vars = [];
        foreach ($data['vars'] ?? [] as $name => $def) {
            $vars[] = new RecipeVar(
                name: $name,
                type: $def['type'] ?? 'string',
                description: $def['description'] ?? ''
            );
        }

        $requires = [];
        foreach ($data['requires'] ?? [] as $req) {
            $requires[] = new RecipeRequirement(
                type: $req['type'] ?? 'binding',
                value: $req['value'] ?? ''
            );
        }

        $files = [];
        foreach ($data['files'] ?? [] as $file) {
            if (!isset($file['path'], $file['template'])) {
                throw new RuntimeException('Each file entry must have "path" and "template" fields');
            }
            $files[] = new RecipeFile(
                path: $file['path'],
                template: $file['template'],
                vars: $file['vars'] ?? []
            );
        }

        $registrations = [];
        foreach ($data['registrations'] ?? [] as $reg) {
            if (!isset($reg['initializer'], $reg['method'], $reg['interface'], $reg['type'])) {
                throw new RuntimeException('Each registration must have "initializer", "method", "interface", and "type" fields');
            }
            $registrations[] = new RecipeRegistration(
                initializer: $reg['initializer'],
                method: $reg['method'],
                interface: $reg['interface'],
                type: $reg['type'],
                key: $reg['key'] ?? null,
                value: $reg['value'] ?? null
            );
        }

        return new Recipe(
            name: $data['name'],
            description: $data['description'] ?? '',
            vars: $vars,
            requires: $requires,
            files: $files,
            registrations: $registrations
        );
    }
}
