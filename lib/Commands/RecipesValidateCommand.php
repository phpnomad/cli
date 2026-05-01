<?php

namespace PHPNomad\Cli\Commands;

use PHPNomad\Cli\Scaffolder\KitDiscoverer;
use PHPNomad\Cli\Scaffolder\Models\Kit;
use PHPNomad\Cli\Scaffolder\RecipeRegistry;
use PHPNomad\Cli\Scaffolder\RecipeValidator;
use PHPNomad\Console\Interfaces\Command;
use PHPNomad\Console\Interfaces\Input;
use PHPNomad\Console\Interfaces\OutputStrategy;

class RecipesValidateCommand implements Command
{
    public function __construct(
        protected OutputStrategy $output,
        protected KitDiscoverer $discoverer,
        protected RecipeRegistry $registry,
        protected RecipeValidator $validator
    ) {
    }

    public function getSignature(): string
    {
        return 'recipes:validate {--path=./:Target project path} {--kit=:Validate only the named kit (vendor/package)}';
    }

    public function getDescription(): string
    {
        return 'Validate recipe kits installed in the project against the recipe schema';
    }

    public function handle(Input $input): int
    {
        $rawPath = $input->getParam('path');
        $path = realpath($rawPath);

        if ($path === false || !is_dir($path)) {
            $this->output->error('Path does not exist: ' . $rawPath);
            return 1;
        }

        $kitFilter = $input->getParam('kit');
        $kits = $this->discoverer->discover($path);

        if ($kitFilter !== null && $kitFilter !== '') {
            if (!isset($kits[$kitFilter])) {
                $this->output->error("Kit not installed: $kitFilter");
                return 1;
            }
            $kits = [$kitFilter => $kits[$kitFilter]];
        }

        $totalRecipes = 0;
        $totalErrors = 0;

        foreach ($kits as $fullName => $kit) {
            $this->output->info($fullName);
            [$recipes, $errors] = $this->validateKit($kit);
            $totalRecipes += $recipes;
            $totalErrors += $errors;
            $this->output->newline();
        }

        if ($kitFilter === null || $kitFilter === '') {
            $localCount = count($this->registry->projectLocalRecipeFiles($path));

            if ($localCount > 0) {
                $this->output->info('Project-local (.phpnomad/recipes/)');
                [$recipes, $errors] = $this->validateProjectLocal($path);
                $totalRecipes += $recipes;
                $totalErrors += $errors;
                $this->output->newline();
            }
        }

        $this->output->info('Summary');
        $this->output->writeln("  $totalRecipes recipe(s) checked");

        if ($totalErrors > 0) {
            $this->output->error("  $totalErrors error(s)");
            return 1;
        }

        $this->output->success('  All recipes valid');
        return 0;
    }

    /**
     * @return array{0: int, 1: int} [recipeCount, errorCount]
     */
    protected function validateKit(Kit $kit): array
    {
        $files = $this->registry->kitRecipeFiles($kit);
        return $this->validateFiles($files);
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function validateProjectLocal(string $projectPath): array
    {
        $files = $this->registry->projectLocalRecipeFiles($projectPath);
        return $this->validateFiles($files);
    }

    /**
     * @param array<string, string> $files
     * @return array{0: int, 1: int}
     */
    protected function validateFiles(array $files): array
    {
        $errorCount = 0;

        foreach ($files as $name => $path) {
            $errors = $this->validator->validate($path);

            if (empty($errors)) {
                $this->output->writeln("  ok    $name.json");
                continue;
            }

            $this->output->error("  fail  $name.json ($path)");

            foreach ($errors as $message) {
                $this->output->writeln('    - ' . $message);
                $errorCount++;
            }
        }

        return [count($files), $errorCount];
    }
}
