<?php

namespace PHPNomad\Cli\Scaffolder;

use PHPNomad\Cli\Scaffolder\Models\Kit;
use PHPNomad\Cli\Scaffolder\Models\Recipe;

class RecipeRegistry
{
    public function __construct(
        protected KitDiscoverer $discoverer,
        protected RecipeLoader $loader,
        protected ProjectConfig $config
    ) {
    }

    /**
     * Return every recipe available to the project, keyed by full identifier (vendor/name or bare name for project-local).
     *
     * @return array<string, Recipe>
     */
    public function all(string $projectPath): array
    {
        $recipes = [];

        foreach ($this->discoverer->discover($projectPath) as $kit) {
            foreach ($this->kitRecipeFiles($kit) as $name => $path) {
                $identifier = $kit->vendor . '/' . $name;
                $recipe = $this->loadSafe($identifier, $projectPath);

                if ($recipe !== null) {
                    $recipes[$identifier] = $recipe;
                }
            }
        }

        foreach ($this->projectLocalRecipeFiles($projectPath) as $name => $path) {
            $recipe = $this->loadSafe($name, $projectPath);

            if ($recipe !== null && !isset($recipes[$name])) {
                $recipes[$name] = $recipe;
            }
        }

        ksort($recipes);

        return $recipes;
    }

    /**
     * Return the active subset of recipes, filtered against .phpnomad/config.json.
     * Project-local recipes are always included (they're implicitly active).
     *
     * @return array<string, Recipe>
     */
    public function active(string $projectPath): array
    {
        $all = $this->all($projectPath);
        $activeList = $this->config->activeRecipes($projectPath);

        if ($activeList === null) {
            return $all;
        }

        $activeSet = array_flip($activeList);
        $result = [];

        foreach ($all as $identifier => $recipe) {
            if ($recipe->originKit === null) {
                $result[$identifier] = $recipe;
                continue;
            }

            if (isset($activeSet[$identifier])) {
                $result[$identifier] = $recipe;
            }
        }

        return $result;
    }

    /**
     * @return array<string, string> recipe-name => absolute-file-path
     */
    public function kitRecipeFiles(Kit $kit): array
    {
        return $this->scanRecipesDir($kit->recipesDir);
    }

    /**
     * @return array<string, string>
     */
    public function projectLocalRecipeFiles(string $projectPath): array
    {
        $current = rtrim($projectPath, '/');

        while (true) {
            $dir = $current . '/.phpnomad/recipes';

            if (is_dir($dir)) {
                return $this->scanRecipesDir($dir);
            }

            $parent = dirname($current);

            if ($parent === $current) {
                return [];
            }

            $current = $parent;
        }
    }

    /**
     * @return array<string, string>
     */
    protected function scanRecipesDir(string $dir): array
    {
        $files = [];

        foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $path) {
            $name = basename($path, '.json');
            $files[$name] = $path;
        }

        return $files;
    }

    protected function loadSafe(string $identifier, string $projectPath): ?Recipe
    {
        try {
            return $this->loader->load($identifier, $projectPath);
        } catch (\Throwable) {
            return null;
        }
    }
}
