<?php

namespace PHPNomad\Cli\Tests\Scaffolder;

use PHPNomad\Cli\Scaffolder\InitializerMutator;
use PHPNomad\Cli\Scaffolder\Models\RecipeRegistration;
use PHPUnit\Framework\TestCase;

class InitializerMutatorTest extends TestCase
{
    private InitializerMutator $mutator;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->mutator = new InitializerMutator();
        $this->tmpDir = sys_get_temp_dir() . '/phpnomad-mutator-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testAppendToExistingListMethod(): void
    {
        $file = $this->writeInitializer(<<<'PHP'
<?php

namespace App;

use PHPNomad\Console\Interfaces\HasCommands;

class AppInit implements HasCommands
{
    public function getCommands(): array
    {
        return [
            ExistingCommand::class,
        ];
    }
}
PHP);

        $reg = new RecipeRegistration(
            initializer: 'App\\AppInit',
            method: 'getCommands',
            interface: 'PHPNomad\\Console\\Interfaces\\HasCommands',
            type: 'list',
            value: 'App\\Commands\\NewCommand'
        );

        $result = $this->mutator->mutate($file, $reg);

        $this->assertTrue($result->success);

        $content = file_get_contents($file);
        $this->assertStringContainsString('NewCommand', $content);
        $this->assertStringContainsString('ExistingCommand', $content);
    }

    public function testAppendToExistingMapMethod(): void
    {
        $file = $this->writeInitializer(<<<'PHP'
<?php

namespace App;

use PHPNomad\Events\Interfaces\HasListeners;

class AppInit implements HasListeners
{
    public function getListeners(): array
    {
        return [
            OldEvent::class => OldListener::class,
        ];
    }
}
PHP);

        $reg = new RecipeRegistration(
            initializer: 'App\\AppInit',
            method: 'getListeners',
            interface: 'PHPNomad\\Events\\Interfaces\\HasListeners',
            type: 'map',
            key: 'App\\Events\\NewEvent',
            value: 'App\\Listeners\\NewListener'
        );

        $result = $this->mutator->mutate($file, $reg);

        $this->assertTrue($result->success);

        $content = file_get_contents($file);
        $this->assertStringContainsString('NewEvent', $content);
        $this->assertStringContainsString('NewListener', $content);
        $this->assertStringContainsString('OldEvent', $content);
    }

    public function testMapKeyCollisionAppendsToArray(): void
    {
        $file = $this->writeInitializer(<<<'PHP'
<?php

namespace App;

use PHPNomad\Events\Interfaces\HasListeners;

class AppInit implements HasListeners
{
    public function getListeners(): array
    {
        return [
            \App\Events\UserCreated::class => \App\Listeners\FirstListener::class,
        ];
    }
}
PHP);

        $reg = new RecipeRegistration(
            initializer: 'App\\AppInit',
            method: 'getListeners',
            interface: 'PHPNomad\\Events\\Interfaces\\HasListeners',
            type: 'map',
            key: 'App\\Events\\UserCreated',
            value: 'App\\Listeners\\SecondListener'
        );

        $result = $this->mutator->mutate($file, $reg);

        $this->assertTrue($result->success);

        $content = file_get_contents($file);
        $this->assertStringContainsString('FirstListener', $content);
        $this->assertStringContainsString('SecondListener', $content);
    }

    public function testCreateMethodWhenMissing(): void
    {
        $file = $this->writeInitializer(<<<'PHP'
<?php

namespace App;

class AppInit
{
}
PHP);

        $reg = new RecipeRegistration(
            initializer: 'App\\AppInit',
            method: 'getCommands',
            interface: 'PHPNomad\\Console\\Interfaces\\HasCommands',
            type: 'list',
            value: 'App\\Commands\\NewCommand'
        );

        $result = $this->mutator->mutate($file, $reg);

        $this->assertTrue($result->success);

        $content = file_get_contents($file);
        $this->assertStringContainsString('function getCommands', $content);
        $this->assertStringContainsString('NewCommand', $content);
        $this->assertStringContainsString('HasCommands', $content);
    }

    public function testDuplicateEntryIsNoOp(): void
    {
        $file = $this->writeInitializer(<<<'PHP'
<?php

namespace App;

use PHPNomad\Console\Interfaces\HasCommands;

class AppInit implements HasCommands
{
    public function getCommands(): array
    {
        return [
            \App\Commands\ExistingCommand::class,
        ];
    }
}
PHP);

        $reg = new RecipeRegistration(
            initializer: 'App\\AppInit',
            method: 'getCommands',
            interface: 'PHPNomad\\Console\\Interfaces\\HasCommands',
            type: 'list',
            value: 'App\\Commands\\ExistingCommand'
        );

        $contentBefore = file_get_contents($file);
        $result = $this->mutator->mutate($file, $reg);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Already registered', $result->message);
    }

    public function testFallbackForNonArrayReturn(): void
    {
        $file = $this->writeInitializer(<<<'PHP'
<?php

namespace App;

use PHPNomad\Console\Interfaces\HasCommands;

class AppInit implements HasCommands
{
    public function getCommands(): array
    {
        return array_merge($this->getCoreCommands(), $this->getAppCommands());
    }
}
PHP);

        $reg = new RecipeRegistration(
            initializer: 'App\\AppInit',
            method: 'getCommands',
            interface: 'PHPNomad\\Console\\Interfaces\\HasCommands',
            type: 'list',
            value: 'App\\Commands\\NewCommand'
        );

        $result = $this->mutator->mutate($file, $reg);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not a simple array literal', $result->message);
        $this->assertNotNull($result->manualEntry);
        $this->assertStringContainsString('NewCommand', $result->manualEntry);
    }

    public function testFileNotFoundReturnsFailure(): void
    {
        $reg = new RecipeRegistration(
            initializer: 'App\\AppInit',
            method: 'getCommands',
            interface: 'PHPNomad\\Console\\Interfaces\\HasCommands',
            type: 'list',
            value: 'App\\Commands\\NewCommand'
        );

        $result = $this->mutator->mutate('/nonexistent/file.php', $reg);

        $this->assertFalse($result->success);
    }

    public function testCreateMapMethodWhenMissing(): void
    {
        $file = $this->writeInitializer(<<<'PHP'
<?php

namespace App;

class AppInit
{
}
PHP);

        $reg = new RecipeRegistration(
            initializer: 'App\\AppInit',
            method: 'getListeners',
            interface: 'PHPNomad\\Events\\Interfaces\\HasListeners',
            type: 'map',
            key: 'App\\Events\\UserCreated',
            value: 'App\\Listeners\\OnUserCreated'
        );

        $result = $this->mutator->mutate($file, $reg);

        $this->assertTrue($result->success);

        $content = file_get_contents($file);
        $this->assertStringContainsString('function getListeners', $content);
        $this->assertStringContainsString('UserCreated', $content);
        $this->assertStringContainsString('OnUserCreated', $content);
        $this->assertStringContainsString('HasListeners', $content);
    }

    public function testMultipleRegistrationsToSameMethod(): void
    {
        $file = $this->writeInitializer(<<<'PHP'
<?php

namespace App;

use PHPNomad\Rest\Interfaces\HasControllers;

class AppInit implements HasControllers
{
    public function getControllers(): array
    {
        return [
            \App\Rest\GetUsers::class,
        ];
    }
}
PHP);

        $reg1 = new RecipeRegistration(
            initializer: 'App\\AppInit',
            method: 'getControllers',
            interface: 'PHPNomad\\Rest\\Interfaces\\HasControllers',
            type: 'list',
            value: 'App\\Rest\\CreateUser'
        );

        $reg2 = new RecipeRegistration(
            initializer: 'App\\AppInit',
            method: 'getControllers',
            interface: 'PHPNomad\\Rest\\Interfaces\\HasControllers',
            type: 'list',
            value: 'App\\Rest\\DeleteUser'
        );

        $this->mutator->mutate($file, $reg1);
        $result = $this->mutator->mutate($file, $reg2);

        $this->assertTrue($result->success);

        $content = file_get_contents($file);
        $this->assertStringContainsString('GetUsers', $content);
        $this->assertStringContainsString('CreateUser', $content);
        $this->assertStringContainsString('DeleteUser', $content);
    }

    public function testDuplicateMapEntryIsNoOp(): void
    {
        $file = $this->writeInitializer(<<<'PHP'
<?php

namespace App;

use PHPNomad\Events\Interfaces\HasListeners;

class AppInit implements HasListeners
{
    public function getListeners(): array
    {
        return [
            \App\Events\UserCreated::class => \App\Listeners\OnUserCreated::class,
        ];
    }
}
PHP);

        $reg = new RecipeRegistration(
            initializer: 'App\\AppInit',
            method: 'getListeners',
            interface: 'PHPNomad\\Events\\Interfaces\\HasListeners',
            type: 'map',
            key: 'App\\Events\\UserCreated',
            value: 'App\\Listeners\\OnUserCreated'
        );

        $result = $this->mutator->mutate($file, $reg);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Already registered', $result->message);
    }

    protected function writeInitializer(string $code): string
    {
        $file = $this->tmpDir . '/Initializer.php';
        file_put_contents($file, $code);

        return $file;
    }

    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
