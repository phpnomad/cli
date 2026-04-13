<?php

namespace PHPNomad\Cli\Commands;

use PHPNomad\Cli\Indexer\ProjectIndexer;
use PHPNomad\Console\Interfaces\Command;
use PHPNomad\Console\Interfaces\Input;
use PHPNomad\Console\Interfaces\OutputStrategy;

class IndexCommand implements Command
{
    public function __construct(
        protected OutputStrategy $output,
        protected ProjectIndexer $indexer
    ) {
    }

    public function getSignature(): string
    {
        return 'index {--path=./:Target project path}';
    }

    public function getDescription(): string
    {
        return 'Scan a PHPNomad project and build the boot sequence index';
    }

    public function handle(Input $input): int
    {
        $path = realpath($input->getParam('path'));

        if ($path === false || !is_dir($path)) {
            $this->output->error('Path does not exist: ' . $input->getParam('path'));
            return 1;
        }

        $index = $this->indexer->index($path, $this->output);

        $this->output->newline();
        $dir = $this->indexer->save($index, $path);
        $this->output->success("Index written to $dir/");
        $this->output->writeln('  meta.json, classes.jsonl, initializers.jsonl, applications.jsonl,');
        $this->output->writeln('  controllers.jsonl, commands.jsonl, dependencies.jsonl,');
        $this->output->writeln('  tables.jsonl, events.jsonl, graphql-types.jsonl,');
        $this->output->writeln('  facades.jsonl, task-handlers.jsonl, mutations.jsonl,');
        $this->output->writeln('  phpnomad-cli.md');

        $this->output->newline();
        $this->output->info('Summary');
        $this->output->writeln('  Applications:   ' . count($index->applications));
        $this->output->writeln('  Initializers:   ' . count($index->initializers));
        $this->output->writeln('  Bindings:       ' . $index->getTotalBindings());
        $this->output->writeln('  Controllers:    ' . count($index->resolvedControllers));
        $this->output->writeln('  Commands:       ' . count($index->resolvedCommands));
        $this->output->writeln('  Tables:         ' . count($index->resolvedTables));
        $this->output->writeln('  Events:         ' . count($index->resolvedEvents));
        $this->output->writeln('  Listeners:      ' . $index->getTotalListeners());
        $this->output->writeln('  GraphQL types:  ' . count($index->resolvedGraphQLTypes));
        $this->output->writeln('  Facades:        ' . count($index->resolvedFacades));
        $this->output->writeln('  Task handlers:  ' . count($index->resolvedTaskHandlers));
        $this->output->writeln('  Mutations:      ' . count($index->resolvedMutations));
        $this->output->writeln('  Dependencies:   ' . count($index->dependencyTrees));
        $this->output->writeln('  Classes:        ' . count($index->classes));

        return 0;
    }
}
