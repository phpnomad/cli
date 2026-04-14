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
        // 1. Load recipe (check project-local .phpnomad/recipes/ first)
        try {
            $recipe = $this->loader->load($from, $projectPath);
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

        $totalFiles = 0;
        $totalRegistrations = 0;

        // 3. Execute child recipes first (recipe stacking)
        if (!empty($recipe->recipes)) {
            // Build the full var context for resolving child recipe vars
            $parentVars = $this->buildFullVarContext($recipe, $vars, $projectPath);

            foreach ($recipe->recipes as $ref) {
                // Resolve var placeholders in the child recipe's var overrides
                $childVars = [];
                foreach ($ref->vars as $key => $value) {
                    $childVars[$key] = $this->resolveVarsInString($value, $parentVars);
                }

                // Merge: parent vars as defaults, child overrides on top
                $mergedVars = array_merge($vars, $childVars);

                $output->newline();
                $result = $this->execute($ref->recipe, $mergedVars, $projectPath, $indexer, $output);

                if ($result !== 0) {
                    return $result;
                }
            }
        }

        // 4. Process this recipe's own files and registrations
        if (!empty($recipe->files) || !empty($recipe->registrations)) {
            $result = $this->executeFilesAndRegistrations($recipe, $vars, $projectPath, $output);

            if ($result !== 0) {
                return $result;
            }
        }

        return 0;
    }

    /**
     * @param array<string, string> $vars
     */
    protected function executeFilesAndRegistrations(Models\Recipe $recipe, array $vars, string $projectPath, OutputStrategy $output): int
    {
        // Pre-compute file namespaces and build registration vars
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

        // Generate files
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

        // Perform registrations
        $registrationsPerformed = 0;

        foreach ($recipe->registrations as $registration) {
            $result = $this->performRegistration($registration, $registrationVars, $projectPath, $output);

            if ($result) {
                $registrationsPerformed++;
            }
        }

        $output->newline();
        $output->success("Done: $filesCreated file(s) created, $registrationsPerformed registration(s) performed.");

        return 0;
    }

    /**
     * Build the full var context including namespace and transforms for resolving child recipe var templates.
     *
     * @param array<string, string> $vars
     * @return array<string, string>
     */
    protected function buildFullVarContext(Models\Recipe $recipe, array $vars, string $projectPath): array
    {
        $context = $vars;

        // Always compute root namespace from PSR-4
        try {
            $rootNamespace = $this->namespaceResolver->resolveRoot($projectPath);
            $context['rootNamespace'] = $rootNamespace;
        } catch (\Throwable) {
            // If root namespace can't be resolved, skip it
        }

        // Compute namespace from first file if available
        if (!empty($recipe->files)) {
            $resolvedPath = $this->resolveVarsInString($recipe->files[0]->path, $vars);

            try {
                $context['namespace'] = $this->namespaceResolver->resolve($resolvedPath, $projectPath);
            } catch (\Throwable) {
                // Fall back to root namespace
                if (isset($rootNamespace)) {
                    $context['namespace'] = $rootNamespace;
                }
            }
        } elseif (isset($rootNamespace)) {
            // Composite recipes with no files use root namespace as default
            $context['namespace'] = $rootNamespace;
        }

        // Add transforms
        foreach ($vars as $key => $value) {
            $context[$key . 'Lower'] = lcfirst($value);
            $context[$key . 'Snake'] = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $value) ?? $value);

            if (str_contains($value, '\\')) {
                $parts = explode('\\', $value);
                $context[$key . 'Short'] = end($parts);
            } else {
                $context[$key . 'Short'] = $value;
            }
        }

        return $context;
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
