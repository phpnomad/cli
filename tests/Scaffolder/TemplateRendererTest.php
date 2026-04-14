<?php

namespace PHPNomad\Cli\Tests\Scaffolder;

use PHPNomad\Cli\Scaffolder\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new TemplateRenderer();
    }

    public function testReplaceVars(): void
    {
        $result = $this->renderer->replaceVars(
            'Hello {{name}}, welcome to {{place}}!',
            ['name' => 'Alex', 'place' => 'PHPNomad']
        );

        $this->assertSame('Hello Alex, welcome to PHPNomad!', $result);
    }

    public function testUnresolvedVarsLeftAsIs(): void
    {
        $result = $this->renderer->replaceVars(
            'Hello {{name}}, your role is {{role}}',
            ['name' => 'Alex']
        );

        $this->assertSame('Hello Alex, your role is {{role}}', $result);
    }

    public function testRenderBuiltinTemplate(): void
    {
        $result = $this->renderer->render('listener', [
            'namespace' => 'App\\Listeners',
            'name' => 'SendWelcomeEmail',
            'event' => 'App\\Events\\UserCreated',
        ]);

        $this->assertStringContainsString('namespace App\\Listeners;', $result);
        $this->assertStringContainsString('class SendWelcomeEmail', $result);
        $this->assertStringContainsString('use App\\Events\\UserCreated;', $result);
        $this->assertStringContainsString('// TODO:', $result);
    }

    public function testRenderFromPath(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tpl_');
        file_put_contents($tmpFile, 'class {{name}} {}');

        try {
            $result = $this->renderer->renderFromPath($tmpFile, ['name' => 'Foo']);

            $this->assertSame('class Foo {}', $result);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testMissingTemplateThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Template not found');

        $this->renderer->render('nonexistent-template', []);
    }

    public function testMultipleOccurrencesReplaced(): void
    {
        $result = $this->renderer->replaceVars(
            '{{name}} and {{name}} again',
            ['name' => 'Foo']
        );

        $this->assertSame('Foo and Foo again', $result);
    }
}
