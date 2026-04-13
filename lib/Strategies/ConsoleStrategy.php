<?php

namespace PHPNomad\Cli\Strategies;

use PHPNomad\Console\Exceptions\ConsoleException;
use PHPNomad\Console\Interfaces\Command;
use PHPNomad\Console\Interfaces\ConsoleStrategy as ConsoleStrategyInterface;
use PHPNomad\Console\Interfaces\HasInterceptors;
use PHPNomad\Console\Interfaces\HasMiddleware;
use PHPNomad\Console\Interfaces\OutputStrategy;
use PHPNomad\Utils\Helpers\Arr;
use PHPNomad\Utils\Helpers\Str;

/**
 * Console strategy with a public dispatch() method.
 *
 * The phpnomad/symfony-console-integration wrapper registers commands with Symfony Console
 * but doesn't expose a dispatch hook suitable for our use case. This strategy parses argv
 * ourselves, matches against registered commands by name, and calls their handle() method.
 *
 * Pattern adapted from Beacon's saas/Strategies/ConsoleStrategy.php.
 */
class ConsoleStrategy implements ConsoleStrategyInterface
{
    /** @var array<int, callable(): Command> */
    protected array $commands = [];

    protected string $usagePrefix = 'phpnomad';

    public function setUsagePrefix(string $prefix): void
    {
        $this->usagePrefix = $prefix;
    }

    public function registerCommand(callable $commandGetter): void
    {
        $this->commands[] = $commandGetter;
    }

    /**
     * Dispatch the command named on the command line.
     *
     * @param array<int, string> $argv Raw PHP $argv.
     */
    public function dispatch(array $argv, OutputStrategy $output): int
    {
        $commandName = $argv[1] ?? null;

        if ($commandName === null || $commandName === 'help' || $commandName === '--help') {
            $this->listCommands($output);
            return 0;
        }

        foreach ($this->commands as $commandGetter) {
            $command = $commandGetter();
            $parsed = $this->parseSignature($command->getSignature());

            if ($parsed['name'] !== $commandName) {
                continue;
            }

            $rawArgs = array_slice($argv, 2);

            if (in_array('--help', $rawArgs, true)) {
                $this->showHelp($command, $parsed, $output);
                return 0;
            }

            [$positional, $options] = $this->splitArgs($rawArgs);

            $params = $this->resolveInputParams($positional, $options, $parsed['definitions']);
            $input = new Input($params);

            try {
                if ($command instanceof HasMiddleware) {
                    foreach ($command->getMiddleware($input) as $middleware) {
                        $middleware->process($input);
                    }
                }

                $exitCode = $command->handle($input);

                if ($command instanceof HasInterceptors) {
                    foreach ($command->getInterceptors($input) as $interceptor) {
                        $interceptor->process($input, $exitCode);
                    }
                }

                return $exitCode;
            } catch (ConsoleException $e) {
                $output->error($e->getMessage());
                return 1;
            }
        }

        $output->error("Unknown command: {$commandName}");
        $this->listCommands($output);
        return 1;
    }

    public function listCommands(OutputStrategy $output): void
    {
        $output->info('PHPNomad CLI');
        $output->newline();
        $output->writeln("Usage: {$this->usagePrefix} <command> [args] [--options]");
        $output->newline();
        $output->info('Available commands:');

        foreach ($this->commands as $commandGetter) {
            $command = $commandGetter();
            $parsed = $this->parseSignature($command->getSignature());
            $output->writeln("  {$parsed['name']}  {$command->getDescription()}");
        }
    }

    /**
     * Separate positional arguments from --options in a raw argv slice.
     *
     * @param array<int, string> $rawArgs
     * @return array{0: array<int, string>, 1: array<string, mixed>}
     */
    protected function splitArgs(array $rawArgs): array
    {
        $positional = [];
        $options = [];

        foreach ($rawArgs as $arg) {
            if (Str::startsWith($arg, '--')) {
                $option = Str::trimLeading($arg, '--');
                if (Str::contains($option, '=')) {
                    [$key, $value] = explode('=', $option, 2);
                    $options[$key] = $value;
                } else {
                    $options[$option] = true;
                }
            } else {
                $positional[] = $arg;
            }
        }

        return [$positional, $options];
    }

    protected function showHelp(Command $command, array $parsed, OutputStrategy $output): void
    {
        $output->info("{$this->usagePrefix} {$parsed['name']}");
        $output->writeln($command->getDescription());

        if (!empty($parsed['definitions'])) {
            $output->newline();
            $output->info('Arguments and options:');
            foreach ($parsed['definitions'] as $def) {
                $marker = $def['isOption'] ? '--' : '';
                $required = $def['required'] ? ' (required)' : '';
                $description = !empty($def['description']) ? " — {$def['description']}" : '';
                $output->writeln("  {$marker}{$def['name']}{$required}{$description}");
            }
        }
    }

    /**
     * Map raw positional + option arrays into the named parameter shape the command expects.
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $options
     * @param array<int, array<string, mixed>> $definitions
     * @return array<string, mixed>
     */
    protected function resolveInputParams(array $args, array $options, array $definitions): array
    {
        $params = [];
        $positionalIndex = 0;

        foreach ($definitions as $definition) {
            $name = $definition['name'];

            if ($definition['isOption']) {
                $params[$name] = Arr::get($options, $name, $definition['default']);
            } else {
                $params[$name] = $args[$positionalIndex] ?? $definition['default'];
                $positionalIndex++;
            }
        }

        return $params;
    }

    /**
     * Parse a PHPNomad command signature string into a name + argument definitions.
     *
     * @return array{name: string, definitions: array<int, array<string, mixed>>}
     */
    public function parseSignature(string $signature): array
    {
        preg_match_all('/{([^}]+)}/', $signature, $matches);
        $rawParams = $matches[1];
        $commandName = trim(preg_replace('/{[^}]+}/', '', $signature));

        $definitions = Arr::process($rawParams)
            ->map(function (string $raw) {
                $isOption = Str::startsWith($raw, '--');
                $description = '';
                $default = null;
                $required = true;

                if (Str::contains($raw, ':')) {
                    [$raw, $description] = explode(':', $raw, 2);
                }

                $raw = trim($raw);

                if ($isOption) {
                    $name = Str::trimLeading($raw, '--');

                    if (Str::contains($name, '=')) {
                        [$name, $default] = explode('=', $name, 2);
                        $default = trim($default);
                        $required = false;
                    }

                    return [
                        'name' => $name,
                        'isOption' => true,
                        'required' => $required,
                        'default' => $default,
                        'description' => $description,
                    ];
                }

                $name = Str::trimTrailing($raw, '?');
                $optional = Str::endsWith($raw, '?');

                return [
                    'name' => $name,
                    'isOption' => false,
                    'required' => !$optional,
                    'default' => null,
                    'description' => $description,
                ];
            })
            ->toArray();

        return [
            'name' => $commandName,
            'definitions' => $definitions,
        ];
    }
}
