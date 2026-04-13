<?php

namespace PHPNomad\Cli\Indexer;

use PHPNomad\Cli\Indexer\Models\ProjectIndex;

class ContextRenderer
{
    protected const SECTIONS = [
        'routes',
        'tables',
        'events',
        'facades',
        'commands',
        'tasks',
        'bindings',
        'graphql',
        'mutations',
    ];

    /**
     * @param list<string>|null $sections
     */
    public function render(ProjectIndex $index, ?array $sections = null): string
    {
        $out = $this->renderHeader($index);

        $renderers = [
            'routes' => fn() => $this->renderRoutes($index),
            'tables' => fn() => $this->renderTables($index),
            'events' => fn() => $this->renderEvents($index),
            'facades' => fn() => $this->renderFacades($index),
            'commands' => fn() => $this->renderCommands($index),
            'tasks' => fn() => $this->renderTaskHandlers($index),
            'bindings' => fn() => $this->renderBindings($index),
            'graphql' => fn() => $this->renderGraphQL($index),
            'mutations' => fn() => $this->renderMutations($index),
        ];

        foreach ($renderers as $name => $renderer) {
            if ($sections !== null && !in_array($name, $sections, true)) {
                continue;
            }

            $section = $renderer();

            if ($section !== '') {
                $out .= "\n" . $section;
            }
        }

        return $out;
    }

    protected function renderHeader(ProjectIndex $index): string
    {
        $meta = $index->getMeta();
        $counts = $meta['counts'];

        $parts = [];

        if ($counts['resolvedControllers'] > 0) {
            $parts[] = "routes: {$counts['resolvedControllers']}";
        }
        if ($counts['resolvedTables'] > 0) {
            $parts[] = "tables: {$counts['resolvedTables']}";
        }
        if ($counts['resolvedEvents'] > 0) {
            $parts[] = "events: {$counts['resolvedEvents']}";
        }
        if ($counts['resolvedCommands'] > 0) {
            $parts[] = "commands: {$counts['resolvedCommands']}";
        }
        if ($counts['resolvedFacades'] > 0) {
            $parts[] = "facades: {$counts['resolvedFacades']}";
        }
        if ($counts['bindings'] > 0) {
            $parts[] = "bindings: {$counts['bindings']}";
        }

        $summary = implode(' | ', $parts);

        return "# Project Context\npath: {$index->projectPath}\nindexed: {$index->indexedAt}\n$summary\n";
    }

    protected function renderRoutes(ProjectIndex $index): string
    {
        if (empty($index->resolvedControllers)) {
            return '';
        }

        $byMethod = [];

        foreach ($index->resolvedControllers as $ctrl) {
            $byMethod[$ctrl->method][] = $ctrl;
        }

        ksort($byMethod);

        $out = "## Routes\n";

        foreach ($byMethod as $method => $controllers) {
            $out .= "\n### $method (" . count($controllers) . ")\n";

            foreach ($controllers as $ctrl) {
                $endpoint = $ctrl->endpoint;

                if ($endpoint === null && $ctrl->usesEndpointBase) {
                    $endpoint = '{base}' . ($ctrl->endpointTail ?? '');
                } elseif ($endpoint === null) {
                    $endpoint = '(dynamic)';
                }

                $name = $this->shortName($ctrl->fqcn);
                $caps = [];

                if ($ctrl->hasMiddleware) {
                    $caps[] = 'middleware';
                }
                if ($ctrl->hasValidations) {
                    $caps[] = 'validations';
                }
                if ($ctrl->hasInterceptors) {
                    $caps[] = 'interceptors';
                }

                $capStr = !empty($caps) ? ' [' . implode(', ', $caps) . ']' : '';
                $out .= str_pad($endpoint, 40) . "$name$capStr\n";
            }
        }

        return $out;
    }

    protected function renderTables(ProjectIndex $index): string
    {
        if (empty($index->resolvedTables)) {
            return '';
        }

        $out = "## Tables\n\n";

        foreach ($index->resolvedTables as $table) {
            $tableName = $table->tableName ?? $this->shortName($table->fqcn);
            $cols = [];

            foreach ($table->columns as $col) {
                $type = strtolower($col['type'] ?? 'unknown');
                $parts = [$type];

                if (!empty($col['typeArgs'])) {
                    $parts = [$type . '[' . implode(',', $col['typeArgs']) . ']'];
                }

                if (!empty($col['factory'])) {
                    if ($col['factory'] === 'PrimaryKeyFactory') {
                        $parts[] = 'PK';
                    } elseif ($col['factory'] === 'ForeignKeyFactory') {
                        $parts[] = 'FK';
                    }
                }

                $attrs = array_filter($col['attributes'] ?? [], fn($a) => !str_starts_with($a, 'REFERENCES'));

                foreach ($attrs as $attr) {
                    $parts[] = strtolower($attr);
                }

                $cols[] = $col['name'] . '(' . implode(' ', $parts) . ')';
            }

            $out .= "$tableName: " . implode(', ', $cols) . "\n";
        }

        return $out;
    }

    protected function renderEvents(ProjectIndex $index): string
    {
        // Build listener map: eventFqcn -> [{handler, initializer}]
        $listenerMap = [];

        foreach ($index->initializers as $init) {
            foreach ($init->listeners as $eventFqcn => $handlerFqcns) {
                foreach ($handlerFqcns as $handlerFqcn) {
                    $listenerMap[$eventFqcn][] = [
                        'handler' => $handlerFqcn,
                        'initializer' => $init->fqcn,
                    ];
                }
            }
        }

        if (empty($index->resolvedEvents) && empty($listenerMap)) {
            return '';
        }

        $out = "## Events\n\n";
        $consumed = [];

        // Resolved events with their listeners
        foreach ($index->resolvedEvents as $event) {
            $id = $event->eventId ?? '(unknown id)';
            $props = [];

            foreach ($event->properties as $prop) {
                $type = $prop['type'] ? $this->lastSegment($prop['type']) : 'mixed';
                $props[] = "{$prop['name']}($type)";
            }

            $propStr = !empty($props) ? ': ' . implode(', ', $props) : '';
            $out .= "$id ({$this->shortName($event->fqcn)})$propStr\n";

            $listeners = $listenerMap[$event->fqcn] ?? [];

            if (empty($listeners)) {
                $out .= "  (no listeners)\n";
            } else {
                foreach ($listeners as $l) {
                    $out .= "  -> {$this->shortName($l['handler'])} (via {$this->shortName($l['initializer'])})\n";
                }
            }

            $consumed[$event->fqcn] = true;
            $out .= "\n";
        }

        // System events: in listeners but not in resolvedEvents
        $systemEvents = array_diff_key($listenerMap, $consumed);

        if (!empty($systemEvents)) {
            $out .= "### System Events (listener-only)\n\n";

            foreach ($systemEvents as $eventFqcn => $listeners) {
                $out .= "{$this->shortName($eventFqcn)}:\n";

                foreach ($listeners as $l) {
                    $out .= "  -> {$this->shortName($l['handler'])} (via {$this->shortName($l['initializer'])})\n";
                }

                $out .= "\n";
            }
        }

        return $out;
    }

    protected function renderFacades(ProjectIndex $index): string
    {
        if (empty($index->resolvedFacades)) {
            return '';
        }

        $out = "## Facades\n\n";

        foreach ($index->resolvedFacades as $facade) {
            $name = $this->lastSegment($facade->fqcn);
            $target = $facade->proxiedInterface
                ? $this->shortName($facade->proxiedInterface)
                : '(unknown)';

            $out .= "$name -> $target\n";
        }

        return $out;
    }

    protected function renderCommands(ProjectIndex $index): string
    {
        if (empty($index->resolvedCommands)) {
            return '';
        }

        $out = "## Commands\n\n";

        foreach ($index->resolvedCommands as $cmd) {
            $sig = $cmd->signature ?? $this->shortName($cmd->fqcn);
            $desc = $cmd->description ? " -- $cmd->description" : '';
            $out .= "$sig$desc\n";
        }

        return $out;
    }

    protected function renderTaskHandlers(ProjectIndex $index): string
    {
        if (empty($index->resolvedTaskHandlers)) {
            return '';
        }

        $out = "## Task Handlers\n\n";

        foreach ($index->resolvedTaskHandlers as $handler) {
            $id = $handler->taskId ?? $this->shortName($handler->taskClass);
            $name = $this->shortName($handler->handlerFqcn);
            $out .= "$id -> $name\n";
        }

        return $out;
    }

    protected function renderBindings(ProjectIndex $index): string
    {
        if (empty($index->initializers) && empty($index->applications)) {
            return '';
        }

        $out = "## Bindings\n";

        foreach ($index->applications as $app) {
            if (!empty($app->preBootstrapBindings)) {
                $out .= "\n### {$this->shortName($app->fqcn)} (pre-bootstrap)\n";

                foreach ($app->preBootstrapBindings as $binding) {
                    $out .= $this->formatBinding($binding) . "\n";
                }
            }
        }

        foreach ($index->initializers as $init) {
            if (empty($init->classDefinitions)) {
                continue;
            }

            $out .= "\n### {$this->shortName($init->fqcn)}\n";

            foreach ($init->classDefinitions as $binding) {
                $out .= $this->formatBinding($binding) . "\n";
            }
        }

        foreach ($index->applications as $app) {
            if (!empty($app->postBootstrapBindings)) {
                $out .= "\n### {$this->shortName($app->fqcn)} (post-bootstrap)\n";

                foreach ($app->postBootstrapBindings as $binding) {
                    $out .= $this->formatBinding($binding) . "\n";
                }
            }
        }

        return $out;
    }

    protected function renderGraphQL(ProjectIndex $index): string
    {
        if (empty($index->resolvedGraphQLTypes)) {
            return '';
        }

        $out = "## GraphQL Types\n\n";

        foreach ($index->resolvedGraphQLTypes as $type) {
            $name = $this->lastSegment($type->fqcn);
            $resolverCount = 0;

            if (!empty($type->resolvers)) {
                foreach ($type->resolvers as $field => $resolverMap) {
                    $resolverCount += count($resolverMap);
                }
            }

            $hasSdl = $type->sdl ? 'sdl present' : 'no sdl';
            $out .= "$name: $hasSdl, $resolverCount resolvers\n";
        }

        return $out;
    }

    protected function renderMutations(ProjectIndex $index): string
    {
        if (empty($index->resolvedMutations)) {
            return '';
        }

        $out = "## Mutations\n\n";

        foreach ($index->resolvedMutations as $mutation) {
            $name = $this->shortName($mutation->fqcn);
            $actions = implode(', ', $mutation->actions);
            $adapter = $mutation->usesAdapter
                ? ' (adapter: ' . $this->lastSegment($mutation->adapterClass ?? 'unknown') . ')'
                : '';

            $out .= "$name: $actions$adapter\n";
        }

        return $out;
    }

    protected function formatBinding(mixed $binding): string
    {
        $type = $binding->bindingType === 'imperative' ? 'factory' : 'bind';
        $concrete = $this->shortName($binding->concrete);
        $abstract = $this->shortName($binding->abstracts[0] ?? '');

        if ($concrete !== $abstract) {
            return "[$type] $concrete -> $abstract";
        }

        return "[$type] $abstract";
    }

    protected function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        if (count($parts) <= 2) {
            return $fqcn;
        }

        return implode('\\', array_slice($parts, -2));
    }

    protected function lastSegment(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
