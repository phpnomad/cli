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

    public function testProjectInstallAdoptsUnmarkedPhpNomadSections(): void
    {
        // Repos that committed the bundled filters by hand (no marker block)
        // must not end up with duplicate [filters.*] tables — that is a TOML
        // parse error and disables every project filter.
        mkdir($this->tmpDir . '/.rtk', 0755, true);
        file_put_contents(
            $this->tmpDir . '/.rtk/filters.toml',
            <<<'TOML'
            schema_version = 1

            [filters.phpnomad-index]
            description = "Stale hand-committed copy"
            match_command = "^phpnomad\\s+index\\b"

            [[tests.phpnomad-index]]
            name = "stale test"
            input = """
            [not-a-real-header] inside a multiline string
            """
            expected = """
            ok
            """

            [filters.custom-user-filter]
            description = "User-defined filter that must survive"
            match_command = "^custom\\b"

            TOML
        );

        $command = new RtkCommand(new FakeOutput());

        $this->assertSame(0, $command->handle(new FakeInput([
            'project' => true,
            'path' => $this->tmpDir,
        ])));

        $content = file_get_contents($this->tmpDir . '/.rtk/filters.toml') ?: '';
        $this->assertSame(1, substr_count($content, '[filters.phpnomad-index]'));
        $this->assertSame(1, substr_count($content, '[filters.phpnomad-make]'));
        $this->assertSame(1, substr_count($content, '[filters.phpnomad-rtk]'));
        $this->assertStringNotContainsString('Stale hand-committed copy', $content);
        $this->assertStringContainsString('[filters.custom-user-filter]', $content);
        $this->assertStringContainsString('User-defined filter that must survive', $content);
    }

    public function testProjectInstallAddsClaudeHook(): void
    {
        $output = new FakeOutput();
        $command = new RtkCommand($output);

        $code = $command->handle(new FakeInput([
            'project' => true,
            'path' => $this->tmpDir,
        ]));

        $this->assertSame(0, $code);
        $this->assertFileExists($this->tmpDir . '/.claude/hooks/phpnomad-rtk.php');
        $this->assertFileExists($this->tmpDir . '/.claude/settings.json');

        $settings = json_decode((string) file_get_contents($this->tmpDir . '/.claude/settings.json'), true);
        $this->assertSame('Bash', $settings['hooks']['PreToolUse'][0]['matcher']);
        $this->assertStringContainsString(
            'phpnomad-rtk.php',
            $settings['hooks']['PreToolUse'][0]['hooks'][0]['command']
        );
    }

    public function testProjectInstallClaudeHookIsIdempotent(): void
    {
        $command = new RtkCommand(new FakeOutput());
        $input = new FakeInput(['project' => true, 'path' => $this->tmpDir]);

        $this->assertSame(0, $command->handle($input));
        $this->assertSame(0, $command->handle($input));

        $settings = json_decode((string) file_get_contents($this->tmpDir . '/.claude/settings.json'), true);
        $this->assertCount(1, $settings['hooks']['PreToolUse']);
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
