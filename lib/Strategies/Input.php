<?php

namespace PHPNomad\Cli\Strategies;

use PHPNomad\Console\Interfaces\Input as InputInterface;

/**
 * Parameter-bag input implementation used by the console strategy.
 */
class Input implements InputInterface
{
    public function __construct(protected array $params = [])
    {
    }

    public function getParam(string $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }

    public function hasParam(string $name): bool
    {
        return array_key_exists($name, $this->params);
    }

    public function setParam(string $name, mixed $value): static
    {
        $this->params[$name] = $value;
        return $this;
    }

    public function removeParam(string $name): static
    {
        unset($this->params[$name]);
        return $this;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function replaceParams(array $params): static
    {
        $this->params = $params;
        return $this;
    }
}
