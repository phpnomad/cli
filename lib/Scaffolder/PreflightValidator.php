<?php

namespace PHPNomad\Cli\Scaffolder;

use PHPNomad\Cli\Indexer\ProjectIndexer;
use PHPNomad\Cli\Scaffolder\Models\Recipe;

class PreflightValidator
{
    public function __construct(
        protected NamespaceResolver $namespaceResolver
    ) {
    }

    /**
     * @param array<string, string> $vars
     * @return string[] List of error messages (empty = valid)
     */
    public function validate(Recipe $recipe, array $vars, string $projectPath, ProjectIndexer $indexer): array
    {
        $errors = [];

        $errors = array_merge($errors, $this->validateVars($recipe, $vars));
        $errors = array_merge($errors, $this->validateFilesDontExist($recipe, $vars, $projectPath));
        $errors = array_merge($errors, $this->validateInitializersExist($recipe, $vars, $projectPath));
        $errors = array_merge($errors, $this->validateRequirements($recipe, $projectPath, $indexer));

        return $errors;
    }

    /**
     * @param array<string, string> $vars
     * @return string[]
     */
    protected function validateVars(Recipe $recipe, array $vars): array
    {
        $errors = [];

        foreach ($recipe->vars as $recipeVar) {
            if (!isset($vars[$recipeVar->name])) {
                $errors[] = "Missing required variable: {$recipeVar->name} ({$recipeVar->description})";
            }
        }

        return $errors;
    }

    /**
     * @param array<string, string> $vars
     * @return string[]
     */
    protected function validateFilesDontExist(Recipe $recipe, array $vars, string $projectPath): array
    {
        $errors = [];

        foreach ($recipe->files as $file) {
            $path = $this->resolveVarsInString($file->path, $vars);
            $fullPath = rtrim($projectPath, '/') . '/' . $path;

            if (file_exists($fullPath)) {
                $errors[] = "File already exists: $path";
            }
        }

        return $errors;
    }

    /**
     * @param array<string, string> $vars
     * @return string[]
     */
    protected function validateInitializersExist(Recipe $recipe, array $vars, string $projectPath): array
    {
        $errors = [];

        foreach ($recipe->registrations as $reg) {
            $initFqcn = $this->resolveVarsInString($reg->initializer, $vars);
            $initPath = $this->namespaceResolver->resolveFilePath($initFqcn, $projectPath);

            if ($initPath === null || !file_exists($initPath)) {
                $errors[] = "Initializer not found: $initFqcn";
            }
        }

        return $errors;
    }

    /**
     * @return string[]
     */
    protected function validateRequirements(Recipe $recipe, string $projectPath, ProjectIndexer $indexer): array
    {
        $errors = [];

        if (empty($recipe->requires)) {
            return $errors;
        }

        $indexDir = rtrim($projectPath, '/') . '/.phpnomad';

        if (!is_dir($indexDir)) {
            return $errors; // Skip requirement checks if no index exists
        }

        try {
            $index = $indexer->load($projectPath);
        } catch (\Throwable) {
            return $errors; // Skip if index can't be loaded
        }

        foreach ($recipe->requires as $requirement) {
            if ($requirement->type === 'binding') {
                if (!$this->hasBinding($index, $requirement->value)) {
                    $errors[] = "Missing required binding: {$requirement->value}. Register it in your initializer before using this recipe.";
                }
            }
        }

        return $errors;
    }

    protected function hasBinding(mixed $index, string $abstractSuffix): bool
    {
        foreach ($index->initializers as $init) {
            foreach ($init->classDefinitions as $binding) {
                foreach ($binding->abstracts as $abstract) {
                    if (str_ends_with($abstract, '\\' . $abstractSuffix) || $abstract === $abstractSuffix) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $vars
     */
    protected function resolveVarsInString(string $template, array $vars): string
    {
        $search = [];
        $replace = [];

        foreach ($vars as $key => $value) {
            $search[] = '{{' . $key . '}}';
            $replace[] = $value;
        }

        return str_replace($search, $replace, $template);
    }
}
