<?php

namespace PHPNomad\Cli\Indexer\Models;

final class IndexedInitializer
{
    /**
     * @param string $fqcn
     * @param string $file
     * @param bool $isVendor
     * @param string[] $implementedInterfaces
     * @param IndexedBinding[] $classDefinitions
     * @param string[] $controllers
     * @param array<string, string[]> $listeners
     * @param array<string, mixed> $eventBindings
     * @param string[] $commands
     * @param array<string, string[]> $mutations
     * @param array<string, string[]> $taskHandlers
     * @param string[] $typeDefinitions
     * @param string[] $updates
     * @param string[] $facades
     * @param bool $hasLoadCondition
     * @param bool $isLoadable
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $file,
        public readonly bool $isVendor,
        public readonly array $implementedInterfaces,
        public readonly array $classDefinitions,
        public readonly array $controllers,
        public readonly array $listeners,
        public readonly array $eventBindings,
        public readonly array $commands,
        public readonly array $mutations,
        public readonly array $taskHandlers,
        public readonly array $typeDefinitions,
        public readonly array $updates,
        public readonly array $facades,
        public readonly bool $hasLoadCondition,
        public readonly bool $isLoadable
    ) {
    }

    public function getSummary(): string
    {
        $parts = [];

        $bindingCount = count($this->classDefinitions);
        if ($bindingCount > 0) {
            $parts[] = $bindingCount . ' binding' . ($bindingCount !== 1 ? 's' : '');
        }

        $controllerCount = count($this->controllers);
        if ($controllerCount > 0) {
            $parts[] = $controllerCount . ' controller' . ($controllerCount !== 1 ? 's' : '');
        }

        $listenerCount = array_sum(array_map('count', $this->listeners));
        if ($listenerCount > 0) {
            $parts[] = $listenerCount . ' listener' . ($listenerCount !== 1 ? 's' : '');
        }

        $commandCount = count($this->commands);
        if ($commandCount > 0) {
            $parts[] = $commandCount . ' command' . ($commandCount !== 1 ? 's' : '');
        }

        $facadeCount = count($this->facades);
        if ($facadeCount > 0) {
            $parts[] = $facadeCount . ' facade' . ($facadeCount !== 1 ? 's' : '');
        }

        $eventBindingCount = count($this->eventBindings);
        if ($eventBindingCount > 0) {
            $parts[] = $eventBindingCount . ' event binding' . ($eventBindingCount !== 1 ? 's' : '');
        }

        $typeDefCount = count($this->typeDefinitions);
        if ($typeDefCount > 0) {
            $parts[] = $typeDefCount . ' type def' . ($typeDefCount !== 1 ? 's' : '');
        }

        if (empty($parts)) {
            return '(empty)';
        }

        return implode(', ', $parts);
    }
}
