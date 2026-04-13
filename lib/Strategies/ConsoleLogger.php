<?php

namespace PHPNomad\Cli\Strategies;

use Exception;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPNomad\Logger\Traits\CanLogException;
use Symfony\Component\Console\Output\OutputInterface;

class ConsoleLogger implements LoggerStrategy
{
    use CanLogException;

    public function __construct(protected OutputInterface $output)
    {
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->output->writeln("<error>[EMERGENCY] $message</error>");
    }

    public function alert(string $message, array $context = []): void
    {
        $this->output->writeln("<error>[ALERT] $message</error>");
    }

    public function critical(string $message, array $context = []): void
    {
        $this->output->writeln("<error>[CRITICAL] $message</error>");
    }

    public function error(string $message, array $context = []): void
    {
        $this->output->writeln("<error>[ERROR] $message</error>");
    }

    public function warning(string $message, array $context = []): void
    {
        $this->output->writeln("<warning>[WARNING] $message</warning>");
    }

    public function notice(string $message, array $context = []): void
    {
        if ($this->output->isVerbose()) {
            $this->output->writeln("<info>[NOTICE] $message</info>");
        }
    }

    public function info(string $message, array $context = []): void
    {
        if ($this->output->isVerbose()) {
            $this->output->writeln("<info>[INFO] $message</info>");
        }
    }

    public function debug(string $message, array $context = []): void
    {
        if ($this->output->isVeryVerbose()) {
            $this->output->writeln("[DEBUG] $message");
        }
    }
}
