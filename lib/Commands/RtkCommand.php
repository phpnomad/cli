<?php

namespace PHPNomad\Cli\Commands;

use PHPNomad\Cli\Support\ClaudeHookInstaller;
use PHPNomad\Console\Interfaces\Command;
use PHPNomad\Console\Interfaces\Input;
use PHPNomad\Console\Interfaces\OutputStrategy;
use RuntimeException;

class RtkCommand implements Command
{
    protected const BEGIN_MARKER = '# BEGIN PHPNomad CLI RTK filters';
    protected const END_MARKER = '# END PHPNomad CLI RTK filters';

    public function __construct(protected OutputStrategy $output)
    {
    }

    public function getSignature(): string
    {
        return 'rtk {--project:Install filters into the target project .rtk/filters.toml} {--global:Install filters into the user RTK filters.toml} {--path=./:Target project path for --project}';
    }

    public function getDescription(): string
    {
        return 'Install PHPNomad RTK filters for token-optimized agent output';
    }

    public function handle(Input $input): int
    {
        $project = (bool) $input->getParam('project');
        $global = (bool) $input->getParam('global');

        if (!$project && !$global) {
            $project = true;
        }

        if ($project && $global) {
            $this->output->error('Choose either --project or --global, not both.');
            return 1;
        }

        $source = $this->getBundledFilterPath();

        if (!is_file($source)) {
            $this->output->error('Bundled RTK filter file is missing: ' . $source);
            return 1;
        }

        $projectPath = null;

        if ($project) {
            $projectPath = $this->resolveProjectPath((string) $input->getParam('path', './'));

            if ($projectPath === null) {
                return 1;
            }
        }

        $target = $global
            ? $this->getGlobalFilterPath()
            : $projectPath . '/.rtk/filters.toml';

        if ($target === null) {
            return 1;
        }

        $filters = file_get_contents($source);

        if ($filters === false) {
            $this->output->error('Could not read bundled RTK filters.');
            return 1;
        }

        $existing = is_file($target) ? file_get_contents($target) : '';

        if ($existing === false) {
            $this->output->error('Could not read existing RTK filters: ' . $target);
            return 1;
        }

        $directory = dirname($target);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($target, $this->mergeFilterContent($existing, $filters));

        $scope = $global ? 'global' : 'project';
        $this->output->success("rtk: installed $scope filters=$target");

        if ($project && $projectPath !== null) {
            if (!$this->installClaudeHook($projectPath)) {
                return 1;
            }

            $this->output->writeln('rtk: run `rtk trust` from the project root before project-local filters apply');
        }

        if ($global) {
            $this->output->writeln('rtk: the Claude Code hook is project-scoped — run `phpnomad rtk --project` inside a project to install it');
        }

        return 0;
    }

    protected function installClaudeHook(string $projectPath): bool
    {
        $installer = new ClaudeHookInstaller($this->getBundledHookScriptPath());

        try {
            $result = $installer->install($projectPath);
        } catch (RuntimeException $e) {
            $this->output->error($e->getMessage());
            return false;
        }

        $this->output->success('rtk: installed Claude Code hook=' . $result['scriptPath']);

        if ($result['settingsChanged']) {
            $this->output->success('rtk: registered PreToolUse hook in ' . $result['settingsPath']);
        } else {
            $this->output->writeln('rtk: PreToolUse hook already registered in ' . $result['settingsPath']);
        }

        return true;
    }

    protected function getBundledHookScriptPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/claude/phpnomad-rtk.php';
    }

    protected function getBundledFilterPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/rtk/filters.toml';
    }

    protected function resolveProjectPath(string $rawPath): ?string
    {
        $path = realpath($rawPath);

        if ($path === false || !is_dir($path)) {
            $this->output->error('Path does not exist: ' . $rawPath);
            return null;
        }

        return rtrim($path, '/');
    }

    protected function getGlobalFilterPath(): ?string
    {
        $configHome = getenv('XDG_CONFIG_HOME');

        if ($configHome === false || $configHome === '') {
            $home = getenv('HOME');

            if ($home === false || $home === '') {
                $this->output->error('Could not determine HOME for global RTK config.');
                return null;
            }

            $configHome = rtrim($home, '/') . '/.config';
        }

        return rtrim($configHome, '/') . '/rtk/filters.toml';
    }

    protected function mergeFilterContent(string $existing, string $filters): string
    {
        $body = preg_replace('/^\s*schema_version\s*=\s*1\s*\R{0,2}/', '', trim($filters)) ?? trim($filters);
        $block = self::BEGIN_MARKER . "\n" . trim($body) . "\n" . self::END_MARKER;

        $cleaned = preg_replace(
            '/\R?' . preg_quote(self::BEGIN_MARKER, '/') . '.*?' . preg_quote(self::END_MARKER, '/') . '\R?/s',
            "\n",
            $existing
        ) ?? $existing;

        $cleaned = $this->stripCollidingSections($cleaned, $this->extractSectionNames($body));

        $cleaned = trim($cleaned);

        if ($cleaned === '') {
            return "schema_version = 1\n\n" . $block . "\n";
        }

        if (!preg_match('/^\s*schema_version\s*=\s*1\s*$/m', $cleaned)) {
            $cleaned = "schema_version = 1\n\n" . $cleaned;
        }

        return rtrim($cleaned) . "\n\n" . $block . "\n";
    }

    /**
     * Table names (`[filters.<name>]` / `[[tests.<name>]]`) defined by the
     * bundled filter block.
     *
     * @return string[]
     */
    protected function extractSectionNames(string $toml): array
    {
        preg_match_all('/^\s*\[\[?\s*(?:filters|tests)\.([A-Za-z0-9_-]+)/m', $toml, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Remove existing `[filters.*]` / `[[tests.*]]` sections whose names
     * collide with the bundled block. Repos that committed the bundled
     * filters by hand (without the marker block) would otherwise end up with
     * duplicate TOML tables — a parse error that disables every filter.
     * Tracks triple-quoted strings so headers inside multiline values are
     * ignored.
     *
     * @param string[] $names
     */
    protected function stripCollidingSections(string $existing, array $names): string
    {
        if ($names === []) {
            return $existing;
        }

        $kept = [];
        $inMultiline = false;
        $skipping = false;

        foreach (preg_split('/\R/', $existing) ?: [] as $line) {
            if (!$inMultiline && preg_match('/^\s*\[/', $line)) {
                $isColliding = preg_match('/^\s*\[\[?\s*(?:filters|tests)\.([A-Za-z0-9_-]+)/', $line, $match)
                    && in_array($match[1], $names, true);
                $skipping = $isColliding;
            }

            if (!$skipping) {
                $kept[] = $line;
            }

            $quoteCount = substr_count($line, '"""') + substr_count($line, "'''");

            if ($quoteCount % 2 === 1) {
                $inMultiline = !$inMultiline;
            }
        }

        return implode("\n", $kept);
    }
}
