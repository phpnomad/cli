<?php

namespace PHPNomad\Cli\Scaffolder;

use PHPNomad\Cli\Scaffolder\Models\Kit;
use RuntimeException;

class TemplateRenderer
{
    /**
     * Render a template by name. The template is resolved relative to the kit that owns the recipe;
     * if no kit is provided, the renderer falls back to <projectPath>/.phpnomad/templates/.
     *
     * @param array<string, string> $vars
     */
    public function render(string $templateName, array $vars, ?Kit $kit = null, ?string $projectPath = null): string
    {
        $path = $this->resolveTemplatePath($templateName, $kit, $projectPath);

        return $this->renderFromPath($path, $vars);
    }

    /**
     * @param array<string, string> $vars
     */
    public function renderFromPath(string $path, array $vars): string
    {
        if (!file_exists($path)) {
            throw new RuntimeException("Template not found: $path");
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException("Could not read template: $path");
        }

        return $this->replaceVars($content, $vars);
    }

    /**
     * @param array<string, string> $vars
     */
    public function replaceVars(string $content, array $vars): string
    {
        $search = [];
        $replace = [];

        foreach ($vars as $key => $value) {
            $search[] = '{{' . $key . '}}';
            $replace[] = $value;
        }

        return str_replace($search, $replace, $content);
    }

    protected function resolveTemplatePath(string $templateName, ?Kit $kit, ?string $projectPath): string
    {
        $filename = $this->templateFilename($templateName);

        if ($kit !== null) {
            return rtrim($kit->templatesDir, '/') . '/' . $filename;
        }

        if ($projectPath !== null) {
            $localPath = $this->findProjectLocalTemplate($filename, $projectPath);

            if ($localPath !== null) {
                return $localPath;
            }
        }

        throw new RuntimeException("Template not found: $templateName. No kit owns the recipe and no project-local .phpnomad/templates/$filename exists.");
    }

    /**
     * Resolve a template reference to a filename. Names ending in `.tpl` are treated as full
     * filenames (so non-PHP templates like `wp-plugin-composer.json.tpl` are supported). Bare
     * names append `.php.tpl` for backward compatibility with the original PHP-only template set.
     */
    protected function templateFilename(string $templateName): string
    {
        if (str_ends_with($templateName, '.tpl')) {
            return $templateName;
        }

        return $templateName . '.php.tpl';
    }

    protected function findProjectLocalTemplate(string $filename, string $startPath): ?string
    {
        $current = rtrim($startPath, '/');

        while (true) {
            $candidate = $current . '/.phpnomad/templates/' . $filename;

            if (file_exists($candidate)) {
                return $candidate;
            }

            $parent = dirname($current);

            if ($parent === $current) {
                return null;
            }

            $current = $parent;
        }
    }
}
