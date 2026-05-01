<?php

namespace PHPNomad\Cli\Scaffolder;

use PHPNomad\Cli\Scaffolder\Models\Kit;
use PHPNomad\Cli\Scaffolder\Models\Recipe;
use PHPNomad\Cli\Scaffolder\Models\RecipeFile;
use PHPNomad\Cli\Scaffolder\Models\RecipeRegistration;
use PHPNomad\Cli\Scaffolder\Models\RecipeReference;
use PHPNomad\Cli\Scaffolder\Models\RecipeRequirement;
use PHPNomad\Cli\Scaffolder\Models\RecipeVar;
use RuntimeException;

class RecipeLoader
{
    public function __construct(
        protected KitDiscoverer $discoverer
    ) {
    }

    public function load(string $from, ?string $projectPath = null): Recipe
    {
        $kit = null;
        $path = $this->resolvePath($from, $projectPath, $kit);

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

        return $this->parse($data, $kit);
    }

    /**
     * Resolve a recipe identifier to a filesystem path. Sets $kit by reference when the recipe is kit-published.
     *
     * Resolution order:
     *   1. Path-like identifiers (contains '/' or ends with '.json') load directly. Note: kit identifiers
     *      like "phpnomad/datastore" also contain '/', so they're checked first.
     *   2. Kit identifiers in vendor/name form resolve via KitDiscoverer.
     *   3. Bare names walk upward from $projectPath looking for .phpnomad/recipes/<name>.json (project-local).
     */
    protected function resolvePath(string $from, ?string $projectPath, ?Kit &$kit): string
    {
        if ($this->looksLikeKitIdentifier($from)) {
            if ($projectPath === null) {
                throw new RuntimeException("Recipe not found: $from. Kit-published recipes require a project path so installed kits can be discovered.");
            }

            $kitPath = $this->resolveKitPath($from, $projectPath, $kit);

            if ($kitPath !== null) {
                return $kitPath;
            }

            [$vendor] = explode('/', $from, 2);
            $installed = $this->discoverer->findByVendor($projectPath, $vendor);

            if ($installed === null) {
                throw new RuntimeException("Recipe not found: $from. No installed kit owned by '$vendor' was found. Run `composer require <vendor>/<package>` to install a kit, or check `phpnomad recipes:list` to see what is available.");
            }

            throw new RuntimeException("Recipe not found: $from. Kit '{$installed->fullName()}' is installed but does not provide a recipe named '" . explode('/', $from, 2)[1] . "'. Run `phpnomad recipes:list --all` to see what each installed kit provides.");
        }

        if (str_ends_with($from, '.json') || str_starts_with($from, '/') || str_starts_with($from, '.')) {
            return $from;
        }

        if ($projectPath !== null) {
            $localPath = $this->findProjectLocalRecipe($from, $projectPath);

            if ($localPath !== null) {
                return $localPath;
            }
        }

        throw new RuntimeException("Recipe not found: $from. No project-local .phpnomad/recipes/$from.json exists. Use the vendor/recipe form (e.g. phpnomad/datastore) to reference a kit-published recipe.");
    }

    protected function looksLikeKitIdentifier(string $from): bool
    {
        if (str_ends_with($from, '.json')) {
            return false;
        }

        if (!str_contains($from, '/')) {
            return false;
        }

        // Treat anything with exactly one slash and no path-like characters as a kit identifier.
        $parts = explode('/', $from);

        if (count($parts) !== 2) {
            return false;
        }

        return $parts[0] !== '' && $parts[1] !== ''
            && !str_contains($from, '\\')
            && !str_starts_with($from, '.')
            && !str_starts_with($from, '/');
    }

    protected function resolveKitPath(string $from, string $projectPath, ?Kit &$kit): ?string
    {
        [$vendor, $recipeName] = explode('/', $from, 2);

        $candidate = $this->discoverer->findByFullName($projectPath, $from);

        if ($candidate !== null) {
            $kit = $candidate;
            return $candidate->recipesDir . '/' . $recipeName . '.json';
        }

        // Fall back to first kit owned by the vendor — covers cases where the user references
        // phpnomad/<recipe> and the recipe lives in any phpnomad-owned kit (e.g., core-recipes).
        $vendorKit = $this->findVendorKitWithRecipe($projectPath, $vendor, $recipeName);

        if ($vendorKit !== null) {
            $kit = $vendorKit;
            return $vendorKit->recipesDir . '/' . $recipeName . '.json';
        }

        return null;
    }

    protected function findVendorKitWithRecipe(string $projectPath, string $vendor, string $recipeName): ?Kit
    {
        foreach ($this->discoverer->discover($projectPath) as $kit) {
            if ($kit->vendor !== $vendor) {
                continue;
            }

            if (file_exists($kit->recipesDir . '/' . $recipeName . '.json')) {
                return $kit;
            }
        }

        return null;
    }

    protected function findProjectLocalRecipe(string $name, string $startPath): ?string
    {
        $current = rtrim($startPath, '/');

        while (true) {
            $candidate = $current . '/.phpnomad/recipes/' . $name . '.json';

            if (file_exists($candidate)) {
                return $candidate;
            }

            $parent = dirname($current);

            if ($parent === $current) {
                return null;
            }

            $current = $parent;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function parse(array $data, ?Kit $kit): Recipe
    {
        if (!isset($data['name'])) {
            throw new RuntimeException('Recipe must have a "name" field');
        }

        $vars = [];
        foreach ($data['vars'] ?? [] as $name => $def) {
            $vars[] = new RecipeVar(
                name: $name,
                type: $def['type'] ?? 'string',
                description: $def['description'] ?? '',
                example: $def['example'] ?? '',
                aiHint: $def['aiHint'] ?? ''
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

        $recipes = [];
        foreach ($data['recipes'] ?? [] as $ref) {
            if (!isset($ref['recipe'])) {
                throw new RuntimeException('Each recipe reference must have a "recipe" field');
            }
            $recipes[] = new RecipeReference(
                recipe: $ref['recipe'],
                vars: $ref['vars'] ?? []
            );
        }

        $relatedPatterns = [];
        foreach ($data['relatedPatterns'] ?? [] as $rel) {
            if (!isset($rel['recipe'], $rel['relationship'])) {
                continue;
            }
            $relatedPatterns[] = ['recipe' => $rel['recipe'], 'relationship' => $rel['relationship']];
        }

        return new Recipe(
            name: $data['name'],
            description: $data['description'] ?? '',
            vars: $vars,
            requires: $requires,
            files: $files,
            registrations: $registrations,
            recipes: $recipes,
            originKit: $kit,
            summary: $data['summary'] ?? '',
            problem: $data['problem'] ?? '',
            appliesWhen: $this->stringArray($data['appliesWhen'] ?? []),
            avoidWhen: $this->stringArray($data['avoidWhen'] ?? []),
            synonyms: $this->synonymsArray($data['synonyms'] ?? []),
            examples: $this->stringArray($data['examples'] ?? []),
            tags: $this->stringArray($data['tags'] ?? []),
            kind: $data['kind'] ?? 'scaffolding',
            tradeoffs: $data['tradeoffs'] ?? '',
            relatedPatterns: $relatedPatterns,
            stability: $data['stability'] ?? '',
            outputs: $this->stringArray($data['outputs'] ?? []),
            postApply: $this->stringArray($data['postApply'] ?? [])
        );
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    protected function stringArray($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @param mixed $value
     * @return array<string, string[]>
     */
    protected function synonymsArray($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $domain => $terms) {
            if (!is_string($domain) || !is_array($terms)) {
                continue;
            }
            $result[$domain] = $this->stringArray($terms);
        }

        return $result;
    }
}
