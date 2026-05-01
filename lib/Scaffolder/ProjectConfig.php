<?php

namespace PHPNomad\Cli\Scaffolder;

use RuntimeException;

class ProjectConfig
{
    /** @var array<string, array{active: ?array<int, string>, path: ?string}> */
    protected array $cache = [];

    /**
     * Read the project's .phpnomad/config.json (walking upward from $startPath).
     * Returns parsed config. When the file is missing, returns a config with active=null
     * meaning "all installed kit recipes are active by default."
     *
     * @return array{active: ?array<int, string>, path: ?string}
     */
    public function load(string $startPath): array
    {
        $startPath = rtrim($startPath, '/');

        if (isset($this->cache[$startPath])) {
            return $this->cache[$startPath];
        }

        $configPath = $this->findConfigFile($startPath);

        if ($configPath === null) {
            return $this->cache[$startPath] = ['active' => null, 'path' => null];
        }

        $contents = file_get_contents($configPath);

        if ($contents === false) {
            throw new RuntimeException("Could not read config file: $configPath");
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            throw new RuntimeException("Invalid JSON in config file: $configPath");
        }

        $active = $data['recipes']['active'] ?? null;

        if ($active !== null && !is_array($active)) {
            throw new RuntimeException("recipes.active must be an array of recipe identifiers in: $configPath");
        }

        if (is_array($active)) {
            $active = array_values(array_filter($active, 'is_string'));
        }

        return $this->cache[$startPath] = ['active' => $active, 'path' => $configPath];
    }

    /**
     * @return ?array<int, string>
     */
    public function activeRecipes(string $startPath): ?array
    {
        return $this->load($startPath)['active'];
    }

    public function isActive(string $startPath, string $recipeName): bool
    {
        $active = $this->activeRecipes($startPath);

        if ($active === null) {
            return true;
        }

        return in_array($recipeName, $active, true);
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }

    protected function findConfigFile(string $startPath): ?string
    {
        $current = rtrim($startPath, '/');

        while (true) {
            $candidate = $current . '/.phpnomad/config.json';

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
}
