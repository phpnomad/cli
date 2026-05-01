<?php

namespace PHPNomad\Cli\Support;

use PHPNomad\Console\Interfaces\OutputStrategy;

class NullOutputStrategy implements OutputStrategy
{
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
        return $this;
    }

    public function error(string $message): static
    {
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
