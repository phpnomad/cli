<?php

namespace PHPNomad\Cli\Scaffolder;

use PHPNomad\Cli\Indexer\ProjectIndexer;
use PHPNomad\Cli\Scaffolder\Models\RecipeRegistration;
use PHPNomad\Console\Interfaces\OutputStrategy;

class RecipeEngine
{
    public function __construct(
        protected RecipeLoader $loader,
        protected TemplateRenderer $renderer,
        protected NamespaceResolver $namespaceResolver,
        protected VarResolver $varResolver,
        protected InitializerMutator $mutator,
        protected PreflightValidator $validator
    ) {
    }

    /**
     * @param array<string, string> $vars
     */
    public function execute(string $from, array $vars, string $projectPath, ProjectIndexer $indexer, OutputStrategy $output): int
    {
        // 1. Load recipe
        try {
            $recipe = $this->loader->load($from);
        } catch (\Throwable $e) {
            $output->error($e->getMessage());
            return 1;
        }

        $output->info("Recipe: {$recipe->name}");
        if ($recipe->description !== '') {
            $output->writeln("  {$recipe->description}");
        }

        // 2. Validate
        $errors = $this->validator->validate($recipe, $vars, $projectPath, $indexer);

        if (!empty($errors)) {
            $output->error('Preflight validation failed:');
            foreach ($errors as $error) {
                $output->writeln("  - $error");
            }
            return 1;
        }

        // 3. Pre-compute file namespaces and build registration vars
        $fileNamespaces = [];

        foreach ($recipe->files as $i => $file) {
            $resolvedPath = $this->resolveVarsInString($file->path, $vars);

            try {
                $fileNamespaces[$i] = $this->namespaceResolver->resolve($resolvedPath, $projectPath);
            } catch (\Throwable $e) {
                $output->error("Could not resolve namespace for $resolvedPath: " . $e->getMessage());
                return 1;
            }
        }

        // Augment vars with namespace from first file (used in registrations)
        $registrationVars = $vars;

        if (!empty($fileNamespaces)) {
            $registrationVars['namespace'] = $fileNamespaces[0];
        }

        // Add auto-computed transforms to registration vars
        foreach ($vars as $key => $value) {
            $registrationVars[$key . 'Lower'] = lcfirst($value);
            $registrationVars[$key . 'Snake'] = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $value) ?? $value);
        }

        // 4. Generate files
        $filesCreated = 0;

        foreach ($recipe->files as $i => $file) {
            $resolvedPath = $this->resolveVarsInString($file->path, $vars);
            $fullPath = rtrim($projectPath, '/') . '/' . $resolvedPath;
            $namespace = $fileNamespaces[$i];

            // Build full vars map with file-specific namespace
            $resolvedVars = $this->varResolver->resolve($vars, $file->vars, $namespace);

            // Render template
            try {
                $content = $this->renderer->render($file->template, $resolvedVars);
            } catch (\Throwable $e) {
                $output->error("Template error: " . $e->getMessage());
                return 1;
            }

            // Create directory and write file
            $dir = dirname($fullPath);

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($fullPath, $content);
            $output->writeln("  Created: $resolvedPath");
            $filesCreated++;
        }

        // 5. Perform registrations
        $registrationsPerformed = 0;

        foreach ($recipe->registrations as $registration) {
            $result = $this->performRegistration($registration, $registrationVars, $projectPath, $output);

            if ($result) {
                $registrationsPerformed++;
            }
        }

        // 5. Summary
        $output->newline();
        $output->success("Done: $filesCreated file(s) created, $registrationsPerformed registration(s) performed.");

        return 0;
    }

    /**
     * @param array<string, string> $vars
     */
    protected function performRegistration(RecipeRegistration $registration, array $vars, string $projectPath, OutputStrategy $output): bool
    {
        // Resolve var placeholders in all registration fields
        $resolved = new RecipeRegistration(
            initializer: $this->resolveVarsInString($registration->initializer, $vars),
            method: $registration->method,
            interface: $registration->interface,
            type: $registration->type,
            key: $registration->key !== null ? $this->resolveVarsInString($registration->key, $vars) : null,
            value: $registration->value !== null ? $this->resolveVarsInString($registration->value, $vars) : null
        );

        // Find initializer file
        $initPath = $this->namespaceResolver->resolveFilePath($resolved->initializer, $projectPath);

        if ($initPath === null || !file_exists($initPath)) {
            $output->error("  Initializer not found: {$resolved->initializer}");
            return false;
        }

        $result = $this->mutator->mutate($initPath, $resolved);

        if ($result->success) {
            $output->writeln("  Registered: {$resolved->method}() in {$resolved->initializer}");
        } else {
            $output->error("  {$result->message}");

            if ($result->manualEntry !== null) {
                $output->writeln("  Please add manually: {$result->manualEntry}");
            }
        }

        return $result->success;
    }

    /**
     * Also resolve namespace-dependent vars (computed from file context) in registration strings.
     *
     * @param array<string, string> $vars
     */
    protected function resolveVarsInString(string $template, array $vars): string
    {
        $search = [];
        $replace = [];

        foreach ($vars as $key => $value) {
            $search[] = '{{' . $key . '}}';
            $replace[] = $value;
        }

        $result = str_replace($search, $replace, $template);

        // Also resolve auto-computed transforms
        $allVars = [];
        foreach ($vars as $key => $value) {
            $allVars[$key] = $value;
            $allVars[$key . 'Lower'] = lcfirst($value);
            $allVars[$key . 'Snake'] = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $value) ?? $value);
        }

        $search2 = [];
        $replace2 = [];

        foreach ($allVars as $key => $value) {
            $search2[] = '{{' . $key . '}}';
            $replace2[] = $value;
        }

        return str_replace($search2, $replace2, $result);
    }
}
