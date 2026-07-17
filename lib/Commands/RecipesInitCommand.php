<?php

namespace PHPNomad\Cli\Commands;

use PHPNomad\Console\Interfaces\Command;
use PHPNomad\Console\Interfaces\Input;
use PHPNomad\Console\Interfaces\OutputStrategy;

class RecipesInitCommand implements Command
{
    public function __construct(protected OutputStrategy $output)
    {
    }

    public function getSignature(): string
    {
        return 'recipes:init {name:Recipe name (lowercase, dash-separated)} {--path=./:Target project path}';
    }

    public function getDescription(): string
    {
        return 'Scaffold a project-local recipe and template stub in .phpnomad/';
    }

    public function handle(Input $input): int
    {
        $name = (string) $input->getParam('name');

        if (!preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $name)) {
            $this->output->error('Invalid recipe name: ' . $name . '. Use lowercase letters, digits, dashes, or underscores (e.g. payout-datastore).');
            return 1;
        }

        $rawPath = (string) $input->getParam('path', './');
        $path = realpath($rawPath);

        if ($path === false || !is_dir($path)) {
            $this->output->error('Path does not exist: ' . $rawPath);
            return 1;
        }

        $path = rtrim($path, '/');
        $recipePath = $path . '/.phpnomad/recipes/' . $name . '.json';
        $templatePath = $path . '/.phpnomad/templates/' . $name . '.php.tpl';

        foreach ([$recipePath, $templatePath] as $target) {
            if (file_exists($target)) {
                $this->output->error('Refusing to overwrite existing file: ' . $target);
                return 1;
            }
        }

        foreach ([dirname($recipePath), dirname($templatePath)] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                $this->output->error('Could not create directory: ' . $dir);
                return 1;
            }
        }

        file_put_contents($recipePath, $this->buildRecipeStub($name));
        file_put_contents($templatePath, $this->buildTemplateStub());

        $this->output->success('recipes: created recipe=' . $recipePath);
        $this->output->success('recipes: created template=' . $templatePath);
        $this->output->writeln('');
        $this->output->writeln('Next steps:');
        $this->output->writeln('  1. Fill in the TODO fields in ' . $name . '.json (problem, appliesWhen, avoidWhen, tags guide AI recipe matching).');
        $this->output->writeln('  2. Flesh out the template in .phpnomad/templates/' . $name . '.php.tpl.');
        $this->output->writeln('  3. Validate with `phpnomad recipes:validate`, then apply with `phpnomad make --from=' . $name . " '{\"name\":\"Example\"}'`.");

        return 0;
    }

    protected function buildRecipeStub(string $name): string
    {
        $stub = [
            'name' => $name,
            'summary' => 'TODO: One-line summary shown in recipes:list (under 100 characters).',
            'description' => 'TODO: Describe what this recipe scaffolds and why.',
            'problem' => "TODO: Describe the user's situation in their own voice, e.g. 'You have a recurring entity that needs persistent storage'.",
            'appliesWhen' => [
                'TODO: A signal that this is the right pattern, e.g. "You need a new service class wired into DI"',
            ],
            'avoidWhen' => [
                'TODO: A signal that this is the wrong pattern, e.g. "The class already exists and only needs a new method"',
            ],
            'tags' => [
                'todo-replace-with-filterable-tags',
            ],
            'vars' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Class name for the generated file.',
                    'example' => 'Payout',
                    'aiHint' => 'PascalCase, singular, no Model/Entity suffix.',
                ],
                'namespace' => [
                    'type' => 'string',
                    'description' => 'Namespace for the generated class. Auto-resolved from composer.json PSR-4 mappings when omitted.',
                    'example' => 'Vendor\\Project\\Services',
                    'aiHint' => 'Omit to let the CLI resolve it from the target path.',
                ],
            ],
            'files' => [
                [
                    'path' => 'lib/{{name}}.php',
                    'template' => $name,
                ],
            ],
        ];

        return (string) json_encode($stub, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    protected function buildTemplateStub(): string
    {
        return <<<'TPL'
<?php

namespace {{namespace}};

class {{name}}
{
    // TODO: Implement {{name}}.
}
TPL . "\n";
    }
}
