<?php

namespace PHPNomad\Cli\Support;

use PHPNomad\Console\Interfaces\OutputStrategy;

class WarningOnlyOutputStrategy implements OutputStrategy
{
    public function __construct(protected OutputStrategy $output)
    {
    }

    public function writeln(string $message): static
    {
        return $this;
    }

    public function info(string $message): static
    {
        return $this;
    }

    public function success(string $message): static
    {
        return $this;
    }

    public function warning(string $message): static
    {
        $this->output->warning($message);
        return $this;
    }

    public function error(string $message): static
    {
        $this->output->error($message);
        return $this;
    }

    public function newline(): static
    {
        return $this;
    }

    public function table(array $rows, array $headers = []): static
    {
        return $this;
    }
}
