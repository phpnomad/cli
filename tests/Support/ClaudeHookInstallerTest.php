<?php

namespace PHPNomad\Cli\Tests\Support;

use PHPNomad\Cli\Support\ClaudeHookInstaller;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ClaudeHookInstallerTest extends TestCase
{
    private string $tmpDir;
    private ClaudeHookInstaller $installer;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpnomad-claude-hook-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->installer = new ClaudeHookInstaller($this->bundledScriptPath());
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testMergeIntoEmptySettingsAddsHook(): void
    {
        $merged = $this->installer->mergeSettings([]);

        $this->assertSame([
            'hooks' => [
                'PreToolUse' => [
                    [
                        'matcher' => 'Bash',
                        'hooks' => [
                            ['type' => 'command', 'command' => ClaudeHookInstaller::HOOK_COMMAND],
                        ],
                    ],
                ],
            ],
        ], $merged);
    }

    public function testMergePreservesUnrelatedKeysAndHooks(): void
    {
        $existing = [
            'permissions' => ['allow' => ['Bash(npm run test:*)']],
            'env' => ['FOO' => 'bar'],
            'hooks' => [
                'PreToolUse' => [
                    [
                        'matcher' => 'Bash',
                        'hooks' => [['type' => 'command', 'command' => 'rtk hook claude']],
                    ],
                ],
                'PostToolUse' => [
                    [
                        'matcher' => 'Write',
                        'hooks' => [['type' => 'command', 'command' => 'echo done']],
                    ],
                ],
            ],
        ];

        $merged = $this->installer->mergeSettings($existing);

        $this->assertSame($existing['permissions'], $merged['permissions']);
        $this->assertSame($existing['env'], $merged['env']);
        $this->assertSame($existing['hooks']['PostToolUse'], $merged['hooks']['PostToolUse']);
        $this->assertCount(2, $merged['hooks']['PreToolUse']);
        $this->assertSame(
            'rtk hook claude',
            $merged['hooks']['PreToolUse'][0]['hooks'][0]['command']
        );
        $this->assertSame(
            ClaudeHookInstaller::HOOK_COMMAND,
            $merged['hooks']['PreToolUse'][1]['hooks'][0]['command']
        );
    }

    public function testMergeIsIdempotent(): void
    {
        $merged = $this->installer->mergeSettings([]);
        $again = $this->installer->mergeSettings($merged);

        $this->assertSame($merged, $again);
        $this->assertCount(1, $again['hooks']['PreToolUse']);
    }

    public function testInstallCreatesScriptAndSettings(): void
    {
        $result = $this->installer->install($this->tmpDir);

        $this->assertFileExists($result['scriptPath']);
        $this->assertFileExists($result['settingsPath']);
        $this->assertTrue($result['settingsChanged']);

        $settings = json_decode((string) file_get_contents($result['settingsPath']), true);
        $this->assertTrue($this->installer->isInstalled($settings));
    }

    public function testInstallIsIdempotentOnReRun(): void
    {
        $this->installer->install($this->tmpDir);
        $result = $this->installer->install($this->tmpDir);

        $this->assertFalse($result['settingsChanged']);

        $settings = json_decode((string) file_get_contents($result['settingsPath']), true);
        $this->assertCount(1, $settings['hooks']['PreToolUse']);
    }

    public function testInstallMergesWithoutClobberingExistingSettingsFile(): void
    {
        mkdir($this->tmpDir . '/.claude', 0755, true);
        file_put_contents(
            $this->tmpDir . '/.claude/settings.json',
            json_encode([
                'model' => 'opus',
                'hooks' => [
                    'PreToolUse' => [
                        ['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'rtk hook claude']]],
                    ],
                ],
            ])
        );

        $result = $this->installer->install($this->tmpDir);
        $settings = json_decode((string) file_get_contents($result['settingsPath']), true);

        $this->assertSame('opus', $settings['model']);
        $this->assertCount(2, $settings['hooks']['PreToolUse']);
        $this->assertSame('rtk hook claude', $settings['hooks']['PreToolUse'][0]['hooks'][0]['command']);
    }

    public function testInstallRejectsInvalidSettingsJson(): void
    {
        mkdir($this->tmpDir . '/.claude', 0755, true);
        file_put_contents($this->tmpDir . '/.claude/settings.json', 'not json{');

        $this->expectException(RuntimeException::class);
        $this->installer->install($this->tmpDir);
    }

    private function bundledScriptPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/claude/phpnomad-rtk.php';
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
