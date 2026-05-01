<?php

namespace PHPNomad\Cli\Commands;

use PHPNomad\Cli\Scaffolder\Models\Recipe;
use PHPNomad\Cli\Scaffolder\RecipeRegistry;
use PHPNomad\Console\Interfaces\Command;
use PHPNomad\Console\Interfaces\Input;
use PHPNomad\Console\Interfaces\OutputStrategy;

class RecipesListCommand implements Command
{
    public function __construct(
        protected OutputStrategy $output,
        protected RecipeRegistry $registry
    ) {
    }

    public function getSignature(): string
    {
        return 'recipes:list {--path=./:Target project path} {--format=table:Output format — table or json} {--all:Show all installed recipes, ignoring the project active filter}';
    }

    public function getDescription(): string
    {
        return 'List recipes available in the current project';
    }

    public function handle(Input $input): int
    {
        $rawPath = $input->getParam('path');
        $path = realpath($rawPath);
        $format = $input->getParam('format');
        $all = (bool) $input->getParam('all');

        if ($path === false || !is_dir($path)) {
            $this->output->error('Path does not exist: ' . $rawPath);
            return 1;
        }

        $recipes = $all ? $this->registry->all($path) : $this->registry->active($path);

        if ($format === 'json') {
            $items = [];
            foreach ($recipes as $identifier => $recipe) {
                $items[] = $this->recipeToArray($identifier, $recipe);
            }
            $this->output->writeln((string) json_encode(['recipes' => $items], JSON_UNESCAPED_SLASHES));
            return 0;
        }

        $this->renderTable($recipes, $all);

        return 0;
    }

    /**
     * @param array<string, Recipe> $recipes
     */
    protected function renderTable(array $recipes, bool $all): void
    {
        if (empty($recipes)) {
            $label = $all ? 'No recipes installed.' : 'No active recipes.';
            $this->output->warning($label);
            $this->output->writeln('');
            $this->output->writeln('Install a recipe kit:');
            $this->output->writeln('  composer require phpnomad/core-recipes');
            $this->output->writeln('Then run this command again, or use --all to see everything once kits are installed.');
            return;
        }

        $byKit = [];
        $projectLocal = [];

        foreach ($recipes as $identifier => $recipe) {
            if ($recipe->originKit === null) {
                $projectLocal[$identifier] = $recipe;
                continue;
            }

            $byKit[$recipe->originKit->fullName()][$identifier] = $recipe;
        }

        ksort($byKit);

        foreach ($byKit as $kitName => $kitRecipes) {
            $this->output->info($kitName);
            foreach ($kitRecipes as $identifier => $recipe) {
                $this->renderRecipeRow($identifier, $recipe);
            }
            $this->output->newline();
        }

        if (!empty($projectLocal)) {
            $this->output->info('Project-local');
            foreach ($projectLocal as $identifier => $recipe) {
                $this->renderRecipeRow($identifier, $recipe);
            }
            $this->output->newline();
        }

        $this->output->info('Summary');
        $this->output->writeln('  ' . count($recipes) . ' recipe(s)' . ($all ? ' (all installed)' : ' (active)'));
    }

    protected function renderRecipeRow(string $identifier, Recipe $recipe): void
    {
        $summary = $recipe->summary !== '' ? $recipe->summary : $recipe->description;
        $stability = $recipe->stability !== '' ? ' [' . $recipe->stability . ']' : '';
        $this->output->writeln('  ' . str_pad($identifier, 40) . $summary . $stability);
    }

    /**
     * @return array<string, mixed>
     */
    protected function recipeToArray(string $identifier, Recipe $recipe): array
    {
        $data = [
            'name' => $identifier,
            'kind' => $recipe->kind,
            'summary' => $recipe->summary,
            'description' => $recipe->description,
            'problem' => $recipe->problem,
            'appliesWhen' => $recipe->appliesWhen,
            'avoidWhen' => $recipe->avoidWhen,
            'synonyms' => $recipe->synonyms,
            'examples' => $recipe->examples,
            'tags' => $recipe->tags,
            'tradeoffs' => $recipe->tradeoffs,
            'relatedPatterns' => $recipe->relatedPatterns,
            'stability' => $recipe->stability,
            'outputs' => $recipe->outputs,
            'postApply' => $recipe->postApply,
            'vars' => array_map(fn($v) => [
                'name' => $v->name,
                'type' => $v->type,
                'description' => $v->description,
                'example' => $v->example,
                'aiHint' => $v->aiHint,
            ], $recipe->vars),
            'requires' => array_map(fn($r) => ['type' => $r->type, 'value' => $r->value], $recipe->requires),
            'composes' => array_map(fn($r) => $r->recipe, $recipe->recipes),
        ];

        if ($recipe->originKit !== null) {
            $data['originKit'] = [
                'vendor' => $recipe->originKit->vendor,
                'packageName' => $recipe->originKit->packageName,
                'fullName' => $recipe->originKit->fullName(),
            ];
        } else {
            $data['originKit'] = null;
        }

        return $data;
    }

}
