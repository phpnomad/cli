<?php

namespace PHPNomad\Cli;

use PHPNomad\Cli\Commands\IndexCommand;
use PHPNomad\Cli\Commands\InspectDiCommand;
use PHPNomad\Cli\Commands\InspectRoutesCommand;
use PHPNomad\Cli\Indexer\Adapters\BootstrapperCallAdapter;
use PHPNomad\Cli\Indexer\Adapters\ConstructorParamAdapter;
use PHPNomad\Cli\Indexer\Adapters\DependencyNodeAdapter;
use PHPNomad\Cli\Indexer\Adapters\IndexedApplicationAdapter;
use PHPNomad\Cli\Indexer\Adapters\IndexedBindingAdapter;
use PHPNomad\Cli\Indexer\Adapters\IndexedClassAdapter;
use PHPNomad\Cli\Indexer\Adapters\IndexedInitializerAdapter;
use PHPNomad\Cli\Indexer\Adapters\InitializerReferenceAdapter;
use PHPNomad\Cli\Indexer\Adapters\ResolvedCommandAdapter;
use PHPNomad\Cli\Indexer\Adapters\ResolvedControllerAdapter;
use PHPNomad\Cli\Indexer\BootSequenceWalker;
use PHPNomad\Cli\Indexer\ClassIndex;
use PHPNomad\Cli\Indexer\CommandAnalyzer;
use PHPNomad\Cli\Indexer\ControllerAnalyzer;
use PHPNomad\Cli\Indexer\DependencyResolver;
use PHPNomad\Cli\Indexer\InitializerAnalyzer;
use PHPNomad\Cli\Indexer\ProjectIndexer;
use PHPNomad\Cli\Strategies\ConsoleStrategy;
use PHPNomad\Console\Interfaces\ConsoleStrategy as ConsoleStrategyInterface;
use PHPNomad\Console\Interfaces\OutputStrategy;
use PHPNomad\Di\Container\Container;
use PHPNomad\Di\Interfaces\InstanceProvider as InstanceProviderInterface;
use PHPNomad\Symfony\Component\Console\Strategies\ConsoleOutputStrategy;
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
            OutputStrategy::class,
            fn() => new ConsoleOutputStrategy($this->container->get(OutputInterface::class))
        );

        $this->container->bindFactory(
            ConsoleStrategy::class,
            fn() => new ConsoleStrategy()
        );

        $this->container->bindFactory(
            ConsoleStrategyInterface::class,
            fn() => $this->container->get(ConsoleStrategy::class)
        );

        // Adapters
        $this->container->bindFactory(ConstructorParamAdapter::class, fn() => new ConstructorParamAdapter());
        $this->container->bindFactory(IndexedBindingAdapter::class, fn() => new IndexedBindingAdapter());
        $this->container->bindFactory(InitializerReferenceAdapter::class, fn() => new InitializerReferenceAdapter());
        $this->container->bindFactory(ResolvedControllerAdapter::class, fn() => new ResolvedControllerAdapter());
        $this->container->bindFactory(ResolvedCommandAdapter::class, fn() => new ResolvedCommandAdapter());
        $this->container->bindFactory(DependencyNodeAdapter::class, fn() => new DependencyNodeAdapter());

        $this->container->bindFactory(
            IndexedClassAdapter::class,
            fn() => new IndexedClassAdapter($this->container->get(ConstructorParamAdapter::class))
        );

        $this->container->bindFactory(
            BootstrapperCallAdapter::class,
            fn() => new BootstrapperCallAdapter($this->container->get(InitializerReferenceAdapter::class))
        );

        $this->container->bindFactory(
            IndexedInitializerAdapter::class,
            fn() => new IndexedInitializerAdapter($this->container->get(IndexedBindingAdapter::class))
        );

        $this->container->bindFactory(
            IndexedApplicationAdapter::class,
            fn() => new IndexedApplicationAdapter(
                $this->container->get(IndexedBindingAdapter::class),
                $this->container->get(BootstrapperCallAdapter::class)
            )
        );

        // Indexer services
        $this->container->bindFactory(ClassIndex::class, fn() => new ClassIndex());
        $this->container->bindFactory(BootSequenceWalker::class, fn() => new BootSequenceWalker());
        $this->container->bindFactory(InitializerAnalyzer::class, fn() => new InitializerAnalyzer());
        $this->container->bindFactory(ControllerAnalyzer::class, fn() => new ControllerAnalyzer());
        $this->container->bindFactory(CommandAnalyzer::class, fn() => new CommandAnalyzer());

        $this->container->bindFactory(
            DependencyResolver::class,
            fn() => new DependencyResolver($this->container->get(ClassIndex::class))
        );

        $this->container->bindFactory(
            ProjectIndexer::class,
            fn() => new ProjectIndexer(
                $this->container->get(ClassIndex::class),
                $this->container->get(BootSequenceWalker::class),
                $this->container->get(InitializerAnalyzer::class),
                $this->container->get(ControllerAnalyzer::class),
                $this->container->get(CommandAnalyzer::class),
                $this->container->get(DependencyResolver::class),
                $this->container->get(IndexedClassAdapter::class),
                $this->container->get(IndexedInitializerAdapter::class),
                $this->container->get(IndexedApplicationAdapter::class),
                $this->container->get(ResolvedControllerAdapter::class),
                $this->container->get(ResolvedCommandAdapter::class),
                $this->container->get(DependencyNodeAdapter::class)
            )
        );

        $this->registerCommands();
    }

    protected function registerCommands(): void
    {
        /** @var ConsoleStrategy $strategy */
        $strategy = $this->container->get(ConsoleStrategy::class);
        $container = $this->container;

        $strategy->registerCommand(function () use ($container) {
            return new InspectRoutesCommand(
                $container->get(OutputStrategy::class),
                $container->get(ProjectIndexer::class)
            );
        });

        $strategy->registerCommand(function () use ($container) {
            return new IndexCommand(
                $container->get(OutputStrategy::class),
                $container->get(ProjectIndexer::class)
            );
        });

        $strategy->registerCommand(function () use ($container) {
            return new InspectDiCommand(
                $container->get(OutputStrategy::class),
                $container->get(ProjectIndexer::class)
            );
        });
    }
}
