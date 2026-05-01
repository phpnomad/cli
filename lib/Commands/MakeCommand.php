<?php

namespace PHPNomad\Cli\Commands;

use PHPNomad\Cli\Indexer\ProjectIndexer;
use PHPNomad\Cli\Scaffolder\RecipeEngine;
use PHPNomad\Console\Interfaces\Command;
use PHPNomad\Console\Interfaces\Input;
use PHPNomad\Console\Interfaces\OutputStrategy;
use InvalidArgumentException;
use JsonException;

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

        // Walk upward to find the project root (directory containing composer.json)
        $projectRoot = $this->findProjectRoot($path);

        if ($projectRoot === null) {
            $this->output->error('Could not find composer.json in ' . $input->getParam('path') . ' or any parent directory.');
            return 1;
        }

        try {
            $vars = $this->parseVars($input);
        } catch (InvalidArgumentException $e) {
            $this->output->error($e->getMessage());
            return 1;
        }

        return $this->engine->execute($from, $vars, $projectRoot, $this->indexer, $this->output);
    }

    protected function findProjectRoot(string $path): ?string
    {
        $current = $path;

        while (true) {
            if (file_exists($current . '/composer.json')) {
                return $current;
            }

            $parent = dirname($current);

            if ($parent === $current) {
                return null;
            }

            $current = $parent;
        }
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

        try {
            $vars = json_decode($varsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Invalid vars JSON: ' . $e->getMessage());
        }

        if (!is_array($vars) || array_is_list($vars)) {
            throw new InvalidArgumentException('Invalid vars JSON: expected an object.');
        }

        $normalized = [];

        foreach ($vars as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Invalid vars JSON: expected object keys to be strings.');
            }

            if (is_array($value) || is_object($value)) {
                throw new InvalidArgumentException("Invalid vars JSON: value for '$key' must be scalar.");
            }

            $normalized[$key] = (string) $value;
        }

        return $normalized;
    }
}
