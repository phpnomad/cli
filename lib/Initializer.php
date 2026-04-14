<?php

namespace PHPNomad\Cli;

use PHPNomad\Cli\Commands\ContextCommand;
use PHPNomad\Cli\Commands\IndexCommand;
use PHPNomad\Cli\Commands\InspectDiCommand;
use PHPNomad\Cli\Commands\InspectRoutesCommand;
use PHPNomad\Cli\Commands\MakeCommand;
use PHPNomad\Cli\Strategies\ConsoleLogger;
use PHPNomad\Console\Interfaces\ConsoleStrategy as ConsoleStrategyInterface;
use PHPNomad\Console\Interfaces\HasCommands;
use PHPNomad\Console\Interfaces\OutputStrategy;
use PHPNomad\Di\Interfaces\CanSetContainer;
use PHPNomad\Di\Traits\HasSettableContainer;
use PHPNomad\Loader\Interfaces\HasClassDefinitions;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPNomad\Symfony\Component\Console\Strategies\ConsoleOutputStrategy;
use PHPNomad\Symfony\Component\Console\Strategies\ConsoleStrategy as SymfonyConsoleStrategy;

class Initializer implements CanSetContainer, HasClassDefinitions, HasCommands
{
    use HasSettableContainer;

    public function getClassDefinitions(): array
    {
        return [
            ConsoleOutputStrategy::class => OutputStrategy::class,
            ConsoleLogger::class => LoggerStrategy::class,
            SymfonyConsoleStrategy::class => [
                ConsoleStrategyInterface::class,
                SymfonyConsoleStrategy::class,
            ],
        ];
    }

    public function getCommands(): array
    {
        return [
            IndexCommand::class,
            InspectDiCommand::class,
            InspectRoutesCommand::class,
            ContextCommand::class,
            MakeCommand::class,
        ];
    }
}
