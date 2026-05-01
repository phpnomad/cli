<?php

namespace PHPNomad\Cli\Tests\Support;

use PHPNomad\Console\Interfaces\OutputStrategy;

class FakeOutput implements OutputStrategy
{
    /** @var string[] */
    public array $lines = [];

    public function writeln(string $message): static
    {
        $this->lines[] = $message;
        return $this;
    }

    public function info(string $message): static
    {
        $this->lines[] = $message;
        return $this;
    }

    public function success(string $message): static
    {
        $this->lines[] = $message;
        return $this;
    }

    public function warning(string $message): static
    {
        $this->lines[] = $message;
        return $this;
    }

    public function error(string $message): static
    {
        $this->lines[] = $message;
        return $this;
    }

    public function newline(): static
    {
        $this->lines[] = '';
        return $this;
    }

    public function table(array $rows, array $headers = []): static
    {
        $this->lines[] = json_encode(['headers' => $headers, 'rows' => $rows]) ?: '';
        return $this;
    }

    public function text(): string
    {
        return implode("\n", $this->lines);
    }
}
