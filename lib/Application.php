<?php

namespace PHPNomad\Cli;

use PHPNomad\Di\Container\Container;
use PHPNomad\Di\Interfaces\InstanceProvider as InstanceProviderInterface;
use PHPNomad\Loader\Bootstrapper;
use PHPNomad\Symfony\Component\Console\Strategies\ConsoleStrategy;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class Application
{
    protected Container $container;

    public function __construct()
    {
        $this->container = new Container();
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function boot(): void
    {
        $this->container->bindFactory(
            InstanceProviderInterface::class,
            fn() => $this->container
        );

        $this->container->bindFactory(
            OutputInterface::class,
            fn() => new ConsoleOutput(OutputInterface::VERBOSITY_NORMAL, null, new OutputFormatter())
        );

        $this->container->bindFactory(
            \Symfony\Component\Console\Application::class,
            fn() => new \Symfony\Component\Console\Application('PHPNomad CLI')
        );

        $bootstrapper = new Bootstrapper(
            $this->container,
            new Initializer()
        );
        $bootstrapper->load();
    }

    public function run(): void
    {
        $this->container->get(ConsoleStrategy::class)->run();
    }
}
