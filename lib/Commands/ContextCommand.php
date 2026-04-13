<?php

namespace PHPNomad\Cli\Commands;

use PHPNomad\Cli\Indexer\ContextRenderer;
use PHPNomad\Cli\Indexer\ProjectIndexer;
use PHPNomad\Console\Interfaces\Command;
use PHPNomad\Console\Interfaces\Input;
use PHPNomad\Console\Interfaces\OutputStrategy;

class ContextCommand implements Command
{
    public function __construct(
        protected OutputStrategy $output,
        protected ProjectIndexer $indexer,
        protected ContextRenderer $renderer
    ) {
    }

    public function getSignature(): string
    {
        return 'context {--path=./:Target project path} {--fresh:Force re-index} {--sections=:Comma-separated sections to include} {--output=stdout:Output destination — stdout or file}';
    }

    public function getDescription(): string
    {
        return 'Generate a compact project context summary for AI agents';
    }

    public function handle(Input $input): int
    {
        $rawPath = $input->getParam('path');
        $path = realpath($rawPath);
        $fresh = $input->getParam('fresh');
        $sectionsParam = $input->getParam('sections');
        $outputDest = $input->getParam('output');

        if ($path === false || !is_dir($path)) {
            $this->output->error('Path does not exist: ' . $rawPath);
            return 1;
        }

        $index = null;

        if (!$fresh) {
            $index = $this->indexer->load($path);
        }

        if ($index === null) {
            $index = $this->indexer->index($path, $this->output);
        }

        $sections = null;

        if ($sectionsParam !== null && $sectionsParam !== '' && $sectionsParam !== true) {
            $sections = array_map('trim', explode(',', $sectionsParam));
        }

        $content = $this->renderer->render($index, $sections);

        if ($outputDest === 'file') {
            $dir = rtrim($path, '/') . '/.phpnomad';

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($dir . '/context.md', $content);
            $this->output->success("Context written to $dir/context.md");
        } else {
            $this->output->writeln($content);
        }

        return 0;
    }
}
