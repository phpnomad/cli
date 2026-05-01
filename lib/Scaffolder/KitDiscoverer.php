<?php

namespace PHPNomad\Cli\Scaffolder;

use PHPNomad\Cli\Scaffolder\Models\Kit;

class KitDiscoverer
{
    /** @var array<string, array<string, Kit>> */
    protected array $cache = [];

    /**
     * @return array<string, Kit> Kits keyed by full name (vendor/package).
     */
    public function discover(string $projectPath): array
    {
        $projectPath = rtrim($projectPath, '/');

        if (isset($this->cache[$projectPath])) {
            return $this->cache[$projectPath];
        }

        $vendorDir = $projectPath . '/vendor';
        $kits = [];

        if (!is_dir($vendorDir)) {
            return $this->cache[$projectPath] = $kits;
        }

        foreach (glob($vendorDir . '/*/*/composer.json') ?: [] as $composerPath) {
            $kit = $this->parseComposer($composerPath);

            if ($kit !== null) {
                $kits[$kit->fullName()] = $kit;
            }
        }

        return $this->cache[$projectPath] = $kits;
    }

    public function findByFullName(string $projectPath, string $fullName): ?Kit
    {
        $kits = $this->discover($projectPath);

        return $kits[$fullName] ?? null;
    }

    public function findByVendor(string $projectPath, string $vendor): ?Kit
    {
        foreach ($this->discover($projectPath) as $kit) {
            if ($kit->vendor === $vendor) {
                return $kit;
            }
        }

        return null;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }

    protected function parseComposer(string $composerPath): ?Kit
    {
        $contents = @file_get_contents($composerPath);

        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            return null;
        }

        $name = $data['name'] ?? null;

        if (!is_string($name) || !str_contains($name, '/')) {
            return null;
        }

        $extra = $data['extra']['phpnomad'] ?? null;

        if (!is_array($extra)) {
            return null;
        }

        $recipesRel = $extra['recipes'] ?? null;
        $templatesRel = $extra['templates'] ?? null;

        if (!is_string($recipesRel) || !is_string($templatesRel)) {
            return null;
        }

        $packageDir = dirname($composerPath);
        $recipesDir = $this->resolvePath($packageDir, $recipesRel);
        $templatesDir = $this->resolvePath($packageDir, $templatesRel);

        if (!is_dir($recipesDir) || !is_dir($templatesDir)) {
            return null;
        }

        [$vendor, $packageName] = explode('/', $name, 2);

        return new Kit(
            vendor: $vendor,
            packageName: $packageName,
            recipesDir: $recipesDir,
            templatesDir: $templatesDir
        );
    }

    protected function resolvePath(string $base, string $relative): string
    {
        return rtrim($base, '/') . '/' . trim($relative, '/');
    }
}
