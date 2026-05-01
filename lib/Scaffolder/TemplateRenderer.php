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
        if ($kit !== null) {
            return rtrim($kit->templatesDir, '/') . '/' . $templateName . '.php.tpl';
        }

        if ($projectPath !== null) {
            $localPath = $this->findProjectLocalTemplate($templateName, $projectPath);

            if ($localPath !== null) {
                return $localPath;
            }
        }

        throw new RuntimeException("Template not found: $templateName. No kit owns the recipe and no project-local .phpnomad/templates/$templateName.php.tpl exists.");
    }

    protected function findProjectLocalTemplate(string $templateName, string $startPath): ?string
    {
        $current = rtrim($startPath, '/');

        while (true) {
            $candidate = $current . '/.phpnomad/templates/' . $templateName . '.php.tpl';

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
