<?php

namespace PHPNomad\Cli\Tests\Commands;

use PHPNomad\Cli\Commands\RtkCommand;
use PHPNomad\Cli\Tests\Support\FakeInput;
use PHPNomad\Cli\Tests\Support\FakeOutput;
use PHPUnit\Framework\TestCase;

class RtkCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpnomad-rtk-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testProjectInstallCreatesTrustedFilterCandidate(): void
    {
        $output = new FakeOutput();
        $command = new RtkCommand($output);

        $code = $command->handle(new FakeInput([
            'project' => true,
            'path' => $this->tmpDir,
        ]));

        $target = $this->tmpDir . '/.rtk/filters.toml';
        $this->assertSame(0, $code);
        $this->assertFileExists($target);

        $content = file_get_contents($target) ?: '';
        $this->assertStringContainsString('schema_version = 1', $content);
        $this->assertStringContainsString('[filters.phpnomad-index]', $content);
        $this->assertStringContainsString('BEGIN PHPNomad CLI RTK filters', $content);
        $this->assertStringContainsString('rtk trust', $output->text());
    }

    public function testProjectInstallReplacesExistingPhpNomadBlock(): void
    {
        mkdir($this->tmpDir . '/.rtk', 0755, true);
        file_put_contents(
            $this->tmpDir . '/.rtk/filters.toml',
            "schema_version = 1\n\n# BEGIN PHPNomad CLI RTK filters\nold\n# END PHPNomad CLI RTK filters\n"
        );

        $command = new RtkCommand(new FakeOutput());

        $this->assertSame(0, $command->handle(new FakeInput([
            'project' => true,
            'path' => $this->tmpDir,
        ])));

        $content = file_get_contents($this->tmpDir . '/.rtk/filters.toml') ?: '';
        $this->assertStringNotContainsString("\nold\n", $content);
        $this->assertSame(1, substr_count($content, '[filters.phpnomad-index]'));
    }

    public function testMutuallyExclusiveScopesFail(): void
    {
        $output = new FakeOutput();
        $command = new RtkCommand($output);

        $code = $command->handle(new FakeInput([
            'project' => true,
            'global' => true,
            'path' => $this->tmpDir,
        ]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Choose either --project or --global', $output->text());
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
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
