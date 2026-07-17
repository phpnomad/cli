#!/usr/bin/env php
<?php
/**
 * PHPNomad RTK Claude Code hook — installed by `phpnomad rtk`.
 *
 * Claude Code invokes this as a PreToolUse hook for Bash tool calls. It reads the
 * tool-call JSON from stdin and, when the command is a phpnomad invocation that is
 * not already routed through rtk, rewrites it to run through rtk. The output shape
 * mirrors `rtk hook claude`: a hookSpecificOutput object with updatedInput. When no
 * rewrite applies, the hook prints nothing and exits 0 so the call passes through.
 */

$raw = stream_get_contents(STDIN);

if ($raw === false || $raw === '') {
    exit(0);
}

$data = json_decode($raw, true);

if (!is_array($data) || ($data['tool_name'] ?? null) !== 'Bash') {
    exit(0);
}

$command = $data['tool_input']['command'] ?? null;

if (!is_string($command) || $command === '') {
    exit(0);
}

$trimmed = ltrim($command);

// Already routed through rtk — leave it alone.
if (preg_match('/^rtk(?:\s|$)/', $trimmed) === 1) {
    exit(0);
}

// Match phpnomad invocations: `phpnomad ...`, `vendor/bin/phpnomad ...`,
// `./vendor/bin/phpnomad ...`, and `php [./]vendor/bin/phpnomad ...`.
$pattern = '#^(?:phpnomad|(?:\./)?vendor/bin/phpnomad|php\s+(?:\./)?vendor/bin/phpnomad)(?:\s|$)#';

if (preg_match($pattern, $trimmed) !== 1) {
    exit(0);
}

echo json_encode([
    'hookSpecificOutput' => [
        'hookEventName' => 'PreToolUse',
        'permissionDecisionReason' => 'PHPNomad RTK auto-rewrite',
        'updatedInput' => ['command' => 'rtk ' . $trimmed],
    ],
], JSON_UNESCAPED_SLASHES);
