<?php

namespace PHPNomad\Cli\Scaffolder;

class VarResolver
{
    /**
     * Resolve the full vars map for a file, merging all var sources and computing transforms.
     *
     * @param array<string, string> $userVars Vars from CLI input
     * @param array<string, string> $fileVars Per-file var overrides from recipe
     * @param string $namespace Auto-computed namespace
     * @return array<string, string>
     */
    public function resolve(array $userVars, array $fileVars, string $namespace): array
    {
        // Start with auto-computed vars
        $vars = ['namespace' => $namespace];

        // Add case transforms for user vars
        foreach ($userVars as $key => $value) {
            $vars[$key] = $value;
            $vars[$key . 'Lower'] = lcfirst($value);
            $vars[$key . 'Snake'] = $this->toSnakeCase($value);
            $vars[$key . 'Short'] = $this->toShortName($value);
        }

        // File-level vars override (may contain {{var}} references)
        foreach ($fileVars as $key => $value) {
            $vars[$key] = $value;
        }

        // Recursively resolve {{var}} references within var values
        $vars = $this->resolveReferences($vars);

        return $vars;
    }

    /**
     * Resolve {{var}} references within var values, up to 10 passes.
     *
     * @param array<string, string> $vars
     * @return array<string, string>
     */
    protected function resolveReferences(array $vars): array
    {
        for ($i = 0; $i < 10; $i++) {
            $changed = false;

            foreach ($vars as $key => $value) {
                if (!is_string($value) || !str_contains($value, '{{')) {
                    continue;
                }

                $resolved = $value;
                foreach ($vars as $refKey => $refValue) {
                    if (!is_string($refValue) || str_contains($refValue, '{{')) {
                        continue;
                    }
                    $resolved = str_replace('{{' . $refKey . '}}', $refValue, $resolved);
                }

                if ($resolved !== $value) {
                    $vars[$key] = $resolved;
                    $changed = true;
                }
            }

            if (!$changed) {
                break;
            }
        }

        return $vars;
    }

    protected function toSnakeCase(string $value): string
    {
        $result = preg_replace('/([a-z])([A-Z])/', '$1_$2', $value);
        return strtolower($result ?? $value);
    }

    /**
     * Extract the short class name from a FQCN, or return the value as-is if not a FQCN.
     */
    protected function toShortName(string $value): string
    {
        if (str_contains($value, '\\')) {
            $parts = explode('\\', $value);
            return end($parts);
        }

        return $value;
    }
}
