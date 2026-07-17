<?php

namespace PHPNomad\Cli\Support;

use RuntimeException;

/**
 * Installs the PHPNomad RTK Claude Code hook into a project: copies the bundled
 * hook script into .claude/hooks/ and merges a PreToolUse entry into the
 * project's .claude/settings.json without clobbering unrelated settings.
 */
class ClaudeHookInstaller
{
    public const HOOK_SCRIPT_RELATIVE_PATH = '.claude/hooks/phpnomad-rtk.php';
    public const HOOK_COMMAND = 'php .claude/hooks/phpnomad-rtk.php';

    /**
     * Marker used to detect a prior install inside an existing settings.json.
     */
    public const HOOK_MARKER = 'phpnomad-rtk.php';

    public function __construct(protected string $bundledScriptPath)
    {
    }

    /**
     * Install the hook script and settings entry into a project.
     *
     * @return array{scriptPath: string, settingsPath: string, settingsChanged: bool}
     */
    public function install(string $projectPath): array
    {
        $projectPath = rtrim($projectPath, '/');

        if (!is_file($this->bundledScriptPath)) {
            throw new RuntimeException('Bundled Claude hook script is missing: ' . $this->bundledScriptPath);
        }

        $script = file_get_contents($this->bundledScriptPath);

        if ($script === false) {
            throw new RuntimeException('Could not read bundled Claude hook script.');
        }

        $scriptPath = $projectPath . '/' . self::HOOK_SCRIPT_RELATIVE_PATH;
        $scriptDir = dirname($scriptPath);

        if (!is_dir($scriptDir) && !mkdir($scriptDir, 0755, true) && !is_dir($scriptDir)) {
            throw new RuntimeException('Could not create directory: ' . $scriptDir);
        }

        if (file_put_contents($scriptPath, $script) === false) {
            throw new RuntimeException('Could not write hook script: ' . $scriptPath);
        }

        chmod($scriptPath, 0755);

        $settingsPath = $projectPath . '/.claude/settings.json';
        $settings = $this->readSettings($settingsPath);
        $merged = $this->mergeSettings($settings);
        $settingsChanged = $merged !== $settings || !is_file($settingsPath);

        if ($settingsChanged) {
            $encoded = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($encoded === false || file_put_contents($settingsPath, $encoded . "\n") === false) {
                throw new RuntimeException('Could not write Claude settings: ' . $settingsPath);
            }
        }

        return [
            'scriptPath' => $scriptPath,
            'settingsPath' => $settingsPath,
            'settingsChanged' => $settingsChanged,
        ];
    }

    /**
     * Merge the PreToolUse hook entry into a decoded settings array. Idempotent:
     * when the hook is already present the settings are returned unchanged. All
     * unrelated keys and existing hooks are preserved.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function mergeSettings(array $settings): array
    {
        if ($this->isInstalled($settings)) {
            return $settings;
        }

        $hooks = is_array($settings['hooks'] ?? null) ? $settings['hooks'] : [];
        $preToolUse = is_array($hooks['PreToolUse'] ?? null) ? $hooks['PreToolUse'] : [];

        $preToolUse[] = [
            'matcher' => 'Bash',
            'hooks' => [
                [
                    'type' => 'command',
                    'command' => self::HOOK_COMMAND,
                ],
            ],
        ];

        $hooks['PreToolUse'] = $preToolUse;
        $settings['hooks'] = $hooks;

        return $settings;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function isInstalled(array $settings): bool
    {
        $hooks = $settings['hooks'] ?? null;
        $preToolUse = is_array($hooks) ? ($hooks['PreToolUse'] ?? null) : null;

        if (!is_array($preToolUse)) {
            return false;
        }

        foreach ($preToolUse as $group) {
            if (!is_array($group)) {
                continue;
            }

            foreach (is_array($group['hooks'] ?? null) ? $group['hooks'] : [] as $hook) {
                if (!is_array($hook)) {
                    continue;
                }

                $command = $hook['command'] ?? '';

                if (is_string($command) && str_contains($command, self::HOOK_MARKER)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function readSettings(string $settingsPath): array
    {
        if (!is_file($settingsPath)) {
            return [];
        }

        $contents = file_get_contents($settingsPath);

        if ($contents === false) {
            throw new RuntimeException('Could not read Claude settings: ' . $settingsPath);
        }

        if (trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Existing Claude settings are not a JSON object: ' . $settingsPath);
        }

        return $decoded;
    }
}
