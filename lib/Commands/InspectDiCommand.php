<?php

namespace PHPNomad\Cli\Commands;

use PHPNomad\Cli\Indexer\Models\BootstrapperCall;
use PHPNomad\Cli\Indexer\Models\IndexedApplication;
use PHPNomad\Cli\Indexer\Models\IndexedBinding;
use PHPNomad\Cli\Indexer\Models\IndexedInitializer;
use PHPNomad\Cli\Indexer\Models\InitializerReference;
use PHPNomad\Cli\Indexer\Models\ProjectIndex;
use PHPNomad\Cli\Indexer\ProjectIndexer;
use PHPNomad\Console\Interfaces\Command;
use PHPNomad\Console\Interfaces\Input;
use PHPNomad\Console\Interfaces\OutputStrategy;

class InspectDiCommand implements Command
{
    public function __construct(
        protected OutputStrategy $output,
        protected ProjectIndexer $indexer
    ) {
    }

    public function getSignature(): string
    {
        return 'inspect:di {--path=./:Target project path} {--format=table:Output format — table or json} {--fresh:Force re-index instead of reading cached index}';
    }

    public function getDescription(): string
    {
        return 'Display the boot sequence and dependency injection bindings for a PHPNomad project';
    }

    public function handle(Input $input): int
    {
        $rawPath = $input->getParam('path');
        $path = realpath($rawPath);
        $format = $input->getParam('format');
        $fresh = $input->getParam('fresh');

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
            $this->output->newline();
        }

        if ($format === 'json') {
            $this->output->writeln($this->indexer->toJson($index));
            return 0;
        }

        $this->renderTable($index);

        return 0;
    }

    protected function renderTable(ProjectIndex $index): void
    {
        $apps = $index->applications;

        if (empty($apps)) {
            $this->output->warning('No PHPNomad applications found.');
            return;
        }

        foreach ($apps as $app) {
            $this->renderApplication($app, $index);
        }

        $this->output->newline();
        $this->output->info('Summary');
        $this->output->writeln('  ' . count($apps) . ' application(s)');
        $this->output->writeln('  ' . count($index->initializers) . ' initializers');
        $this->output->writeln('  ' . $index->getTotalBindings() . ' bindings');
        $this->output->writeln('  ' . $index->getTotalControllers() . ' controllers');
        $this->output->writeln('  ' . count($index->resolvedCommands) . ' commands');
        $this->output->writeln('  ' . $index->getTotalListeners() . ' listeners');
        $this->output->writeln('  ' . count($index->resolvedTables) . ' tables');
        $this->output->writeln('  ' . count($index->resolvedEvents) . ' events');
        $this->output->writeln('  ' . count($index->resolvedFacades) . ' facades');
        $this->output->writeln('  ' . count($index->resolvedTaskHandlers) . ' task handlers');
        $this->output->writeln('  ' . count($index->resolvedMutations) . ' mutations');
        $this->output->writeln('  ' . count($index->dependencyTrees) . ' dependency trees');
    }

    protected function renderApplication(IndexedApplication $app, ProjectIndex $index): void
    {
        $this->output->newline();
        $this->output->info('Application: ' . $app->fqcn . ' (' . $app->file . ')');

        $preBindings = $app->preBootstrapBindings;

        if (!empty($preBindings)) {
            $this->output->newline();
            $this->output->writeln('Pre-bootstrap bindings:');

            foreach ($preBindings as $binding) {
                $this->renderBinding($binding, '  ');
            }
        }

        foreach ($app->bootstrapperCalls as $call) {
            $this->renderBootstrapperCall($call, $index);
        }

        $postBindings = $app->postBootstrapBindings;

        if (!empty($postBindings)) {
            $this->output->newline();
            $this->output->writeln('Post-bootstrap bindings:');

            foreach ($postBindings as $binding) {
                $this->renderBinding($binding, '  ');
            }
        }
    }

    protected function renderBootstrapperCall(BootstrapperCall $call, ProjectIndex $index): void
    {
        $refs = $call->initializers;
        $this->output->newline();
        $this->output->writeln('Boot sequence (' . count($refs) . ' initializers, ' . $call->method . '()):');

        $number = 0;

        foreach ($refs as $ref) {
            $number++;

            if ($ref->isDynamic) {
                $this->output->writeln('  ' . $this->pad('#' . $number, 5) . $this->formatDynamic($ref));
                continue;
            }

            $fqcn = $ref->fqcn;
            $init = $index->initializers[$fqcn] ?? null;

            if ($init === null) {
                $this->output->writeln('  ' . $this->pad('#' . $number, 5) . $this->shortName($fqcn ?? '') . ' (not analyzed)');
                continue;
            }

            $vendorTag = $init->isVendor ? ' (vendor)' : '';
            $summary = $init->getSummary();
            $label = $this->shortName($fqcn ?? '') . $vendorTag;
            $this->output->writeln('  ' . $this->pad('#' . $number, 5) . $this->pad($label, 48) . $summary);
        }
    }

    protected function renderBinding(IndexedBinding $binding, string $indent): void
    {
        $type = $binding->bindingType === 'imperative' ? 'factory' : 'bind';
        $abstract = $this->shortName($binding->abstracts[0] ?? '');

        if ($binding->bindingType === 'declarative' || $binding->concrete !== ($binding->abstracts[0] ?? '')) {
            $concrete = $this->shortName($binding->concrete);
            $this->output->writeln($indent . "[$type] $concrete -> $abstract");
        } else {
            $this->output->writeln($indent . "[$type] $abstract");
        }
    }

    protected function formatDynamic(InitializerReference $ref): string
    {
        return '--- ' . $ref->source . ' (dynamic) ---';
    }

    protected function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        if (count($parts) <= 3) {
            return $fqcn;
        }

        return implode('\\', array_slice($parts, -3));
    }

    protected function pad(string $text, int $width): string
    {
        return str_pad($text, $width);
    }
}
