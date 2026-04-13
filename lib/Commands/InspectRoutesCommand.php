<?php

namespace PHPNomad\Cli\Commands;

use PHPNomad\Cli\Indexer\Models\ProjectIndex;
use PHPNomad\Cli\Indexer\Models\ResolvedController;
use PHPNomad\Cli\Indexer\ProjectIndexer;
use PHPNomad\Console\Interfaces\Command;
use PHPNomad\Console\Interfaces\Input;
use PHPNomad\Console\Interfaces\OutputStrategy;

class InspectRoutesCommand implements Command
{
    public function __construct(
        protected OutputStrategy $output,
        protected ProjectIndexer $indexer
    ) {
    }

    public function getSignature(): string
    {
        return 'inspect:routes {--path=./:Target project path} {--format=table:Output format — table or json} {--fresh:Force re-index instead of reading cached index}';
    }

    public function getDescription(): string
    {
        return 'List REST routes in a PHPNomad project with their capabilities';
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
        $controllers = $index->resolvedControllers;

        if (empty($controllers)) {
            $this->output->warning('No resolved controllers found.');
            return;
        }

        // Group by HTTP method
        $grouped = [];
        foreach ($controllers as $ctrl) {
            $grouped[$ctrl->method][] = $ctrl;
        }

        // Sort methods in conventional order
        $methodOrder = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'UNKNOWN'];

        foreach ($methodOrder as $method) {
            if (!isset($grouped[$method])) {
                continue;
            }

            $this->output->newline();
            $this->output->info($method . ' (' . count($grouped[$method]) . ')');

            foreach ($grouped[$method] as $ctrl) {
                $this->renderController($ctrl);
            }
        }

        // Any methods not in the conventional order
        foreach ($grouped as $method => $ctrls) {
            if (in_array($method, $methodOrder, true)) {
                continue;
            }

            $this->output->newline();
            $this->output->info($method . ' (' . count($ctrls) . ')');

            foreach ($ctrls as $ctrl) {
                $this->renderController($ctrl);
            }
        }

        $this->output->newline();
        $this->output->info('Summary');
        $this->output->writeln('  ' . count($controllers) . ' controller(s)');

        $withMiddleware = count(array_filter($controllers, fn($c) => $c->hasMiddleware));
        $withValidations = count(array_filter($controllers, fn($c) => $c->hasValidations));
        $withInterceptors = count(array_filter($controllers, fn($c) => $c->hasInterceptors));
        $withEndpointBase = count(array_filter($controllers, fn($c) => $c->usesEndpointBase));

        $this->output->writeln('  ' . $withMiddleware . ' with middleware');
        $this->output->writeln('  ' . $withValidations . ' with validations');
        $this->output->writeln('  ' . $withInterceptors . ' with interceptors');
        $this->output->writeln('  ' . $withEndpointBase . ' using WithEndpointBase');
    }

    protected function renderController(ResolvedController $ctrl): void
    {
        $endpoint = $ctrl->endpoint ?? $ctrl->endpointTail ?? '(dynamic)';

        if ($ctrl->usesEndpointBase && $ctrl->endpointTail !== null) {
            $endpoint = '{base}' . $ctrl->endpointTail;
        }

        $capabilities = [];
        if ($ctrl->hasMiddleware) $capabilities[] = 'middleware';
        if ($ctrl->hasValidations) $capabilities[] = 'validations';
        if ($ctrl->hasInterceptors) $capabilities[] = 'interceptors';

        $capStr = !empty($capabilities) ? ' [' . implode(', ', $capabilities) . ']' : '';

        $this->output->writeln('  ' . $this->pad($endpoint, 40) . $this->shortName($ctrl->fqcn) . $capStr);
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
