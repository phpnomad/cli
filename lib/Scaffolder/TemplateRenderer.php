<?php

namespace PHPNomad\Cli\Scaffolder;

use RuntimeException;

class TemplateRenderer
{
    /**
     * @param array<string, string> $vars
     */
    public function render(string $templateName, array $vars): string
    {
        $path = __DIR__ . '/Templates/' . $templateName . '.php.tpl';

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
}
