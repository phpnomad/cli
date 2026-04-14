<?php

namespace PHPNomad\Cli\Commands;

use PHPNomad\Cli\Indexer\ProjectIndexer;
use PHPNomad\Cli\Scaffolder\RecipeEngine;
use PHPNomad\Console\Interfaces\Command;
use PHPNomad\Console\Interfaces\Input;
use PHPNomad\Console\Interfaces\OutputStrategy;

class MakeCommand implements Command
{
    public function __construct(
        protected OutputStrategy $output,
        protected RecipeEngine $engine,
        protected ProjectIndexer $indexer
    ) {
    }

    public function getSignature(): string
    {
        return 'make {--from=:Recipe name or path to JSON spec} {--path=./:Target project path} {vars?:Variables as JSON object}';
    }

    public function getDescription(): string
    {
        return 'Generate PHP files from a recipe spec and register them in the project';
    }

    public function handle(Input $input): int
    {
        $from = $input->getParam('from');

        if (empty($from)) {
            $this->output->error('The --from option is required. Provide a recipe name (e.g. listener) or a path to a JSON spec.');
            return 1;
        }

        $path = realpath($input->getParam('path'));

        if ($path === false || !is_dir($path)) {
            $this->output->error('Path does not exist: ' . $input->getParam('path'));
            return 1;
        }

        $vars = $this->parseVars($input);

        return $this->engine->execute($from, $vars, $path, $this->indexer, $this->output);
    }

    /**
     * @return array<string, string>
     */
    protected function parseVars(Input $input): array
    {
        $varsJson = $input->getParam('vars');

        if (empty($varsJson)) {
            return [];
        }

        $vars = json_decode($varsJson, true);

        if (!is_array($vars)) {
            return [];
        }

        return $vars;
    }
}
