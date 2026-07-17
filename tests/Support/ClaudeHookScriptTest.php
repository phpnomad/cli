<?php

namespace PHPNomad\Cli\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the bundled Claude Code hook script end-to-end by piping tool-call
 * JSON through it, the same way Claude Code invokes it.
 */
class ClaudeHookScriptTest extends TestCase
{
    #[DataProvider('rewriteProvider')]
    public function testRewritesPhpNomadInvocations(string $command): void
    {
        $output = $this->runHook(['tool_name' => 'Bash', 'tool_input' => ['command' => $command]]);
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded, 'Hook should emit JSON for: ' . $command);
        $this->assertSame('PreToolUse', $decoded['hookSpecificOutput']['hookEventName']);
        $this->assertSame('rtk ' . $command, $decoded['hookSpecificOutput']['updatedInput']['command']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rewriteProvider(): array
    {
        return [
            'bare' => ['phpnomad index --path=.'],
            'vendor-bin' => ['vendor/bin/phpnomad context --sections=routes'],
            'dot-slash-vendor-bin' => ['./vendor/bin/phpnomad make --from=listener'],
            'php-vendor-bin' => ['php vendor/bin/phpnomad index --path=.'],
            'php-dot-slash-vendor-bin' => ['php ./vendor/bin/phpnomad recipes:list'],
        ];
    }

    #[DataProvider('passthroughProvider')]
    public function testLeavesOtherCommandsAlone(string $command): void
    {
        $output = $this->runHook(['tool_name' => 'Bash', 'tool_input' => ['command' => $command]]);

        $this->assertSame('', $output, 'Hook should stay silent for: ' . $command);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function passthroughProvider(): array
    {
        return [
            'already-prefixed' => ['rtk phpnomad index --path=.'],
            'already-prefixed-vendor-bin' => ['rtk vendor/bin/phpnomad index'],
            'unrelated' => ['git status'],
            'phpnomad-substring' => ['echo phpnomad index'],
            'phpnomad-like-binary' => ['phpnomadx index'],
        ];
    }

    public function testIgnoresNonBashTools(): void
    {
        $output = $this->runHook(['tool_name' => 'Write', 'tool_input' => ['command' => 'phpnomad index']]);

        $this->assertSame('', $output);
    }

    public function testIgnoresMalformedInput(): void
    {
        $this->assertSame('', $this->runHookRaw('not json{'));
        $this->assertSame('', $this->runHookRaw(''));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function runHook(array $payload): string
    {
        return $this->runHookRaw((string) json_encode($payload));
    }

    private function runHookRaw(string $stdin): string
    {
        $script = dirname(__DIR__, 2) . '/resources/claude/phpnomad-rtk.php';
        $this->assertFileExists($script);

        $process = proc_open(
            [PHP_BINARY, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        $this->assertIsResource($process);

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, 'Hook script should exit 0. stderr: ' . $stderr);
        $this->assertSame('', $stderr, 'Hook script should not write to stderr.');

        return $stdout;
    }
}
