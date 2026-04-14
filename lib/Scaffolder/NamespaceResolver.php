<?php

namespace PHPNomad\Cli\Scaffolder;

use RuntimeException;

class NamespaceResolver
{
    /**
     * Resolve the PHP namespace for a file path based on the project's PSR-4 autoload config.
     */
    public function resolve(string $filePath, string $projectPath): string
    {
        $psr4 = $this->loadPsr4Mapping($projectPath);
        $dir = dirname($filePath);

        $bestPrefix = null;
        $bestNamespace = null;
        $bestLength = 0;

        foreach ($psr4 as $namespace => $dirs) {
            foreach ((array)$dirs as $autoloadDir) {
                $autoloadDir = rtrim($autoloadDir, '/');

                if ($dir === $autoloadDir) {
                    if (strlen($autoloadDir) > $bestLength) {
                        $bestLength = strlen($autoloadDir);
                        $bestNamespace = rtrim($namespace, '\\');
                        $bestPrefix = $autoloadDir;
                    }
                } elseif (str_starts_with($dir, $autoloadDir . '/')) {
                    if (strlen($autoloadDir) > $bestLength) {
                        $bestLength = strlen($autoloadDir);
                        $remaining = substr($dir, strlen($autoloadDir) + 1);
                        $bestNamespace = rtrim($namespace, '\\') . '\\' . str_replace('/', '\\', $remaining);
                        $bestPrefix = $autoloadDir;
                    }
                }
            }
        }

        if ($bestNamespace === null) {
            throw new RuntimeException("Could not resolve namespace for path: $filePath (no matching PSR-4 mapping in $projectPath/composer.json)");
        }

        return $bestNamespace;
    }

    /**
     * Reverse lookup: resolve a FQCN to its file path in the project.
     */
    public function resolveFilePath(string $fqcn, string $projectPath): ?string
    {
        $psr4 = $this->loadPsr4Mapping($projectPath);

        foreach ($psr4 as $namespace => $dirs) {
            $namespace = rtrim($namespace, '\\') . '\\';

            if (str_starts_with($fqcn, $namespace)) {
                $relative = substr($fqcn, strlen($namespace));
                $relativePath = str_replace('\\', '/', $relative) . '.php';

                foreach ((array)$dirs as $dir) {
                    $fullPath = rtrim($projectPath, '/') . '/' . rtrim($dir, '/') . '/' . $relativePath;

                    if (file_exists($fullPath)) {
                        return $fullPath;
                    }
                }

                // Return the first mapping even if file doesn't exist yet
                $dir = (array)$dirs;
                return rtrim($projectPath, '/') . '/' . rtrim($dir[0], '/') . '/' . $relativePath;
            }
        }

        return null;
    }

    /**
     * @return array<string, string|string[]>
     */
    protected function loadPsr4Mapping(string $projectPath): array
    {
        $composerPath = rtrim($projectPath, '/') . '/composer.json';

        if (!file_exists($composerPath)) {
            throw new RuntimeException("composer.json not found at: $composerPath");
        }

        $contents = file_get_contents($composerPath);

        if ($contents === false) {
            throw new RuntimeException("Could not read: $composerPath");
        }

        $composer = json_decode($contents, true);

        if (!is_array($composer)) {
            throw new RuntimeException("Invalid JSON in: $composerPath");
        }

        $psr4 = $composer['autoload']['psr-4'] ?? [];

        if (empty($psr4)) {
            throw new RuntimeException("No PSR-4 autoload mapping found in: $composerPath");
        }

        return $psr4;
    }
}
