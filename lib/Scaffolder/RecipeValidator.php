<?php

namespace PHPNomad\Cli\Scaffolder;

class RecipeValidator
{
    private const VALID_KINDS = ['scaffolding', 'advisory', 'composite'];
    private const VALID_STABILITIES = ['stable', 'experimental', 'deprecated'];
    private const REGISTRATION_TYPES = ['map', 'list'];
    private const SUMMARY_MAX_LENGTH = 100;

    /**
     * Validate a recipe file.
     *
     * @return string[] List of validation errors. Empty array means the file is valid.
     */
    public function validate(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return ["File does not exist: $filePath"];
        }

        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return ["Could not read file: $filePath"];
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            return ["Invalid JSON in $filePath: " . json_last_error_msg()];
        }

        return $this->validateData($data);
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    public function validateData(array $data): array
    {
        $errors = [];

        if (!isset($data['name']) || !is_string($data['name']) || $data['name'] === '') {
            $errors[] = 'Recipe must have a non-empty "name" field (string)';
        }

        $this->checkString($data, 'description', $errors);
        $this->checkString($data, 'summary', $errors);
        $this->checkString($data, 'problem', $errors);
        $this->checkString($data, 'tradeoffs', $errors);

        if (isset($data['summary']) && is_string($data['summary']) && strlen($data['summary']) > self::SUMMARY_MAX_LENGTH) {
            $errors[] = sprintf('"summary" must be %d characters or fewer (got %d)', self::SUMMARY_MAX_LENGTH, strlen($data['summary']));
        }

        $this->checkStringArray($data, 'appliesWhen', $errors);
        $this->checkStringArray($data, 'avoidWhen', $errors);
        $this->checkStringArray($data, 'examples', $errors);
        $this->checkStringArray($data, 'tags', $errors);
        $this->checkStringArray($data, 'outputs', $errors);
        $this->checkStringArray($data, 'postApply', $errors);

        $this->checkEnum($data, 'kind', self::VALID_KINDS, $errors);
        $this->checkEnum($data, 'stability', self::VALID_STABILITIES, $errors);

        $this->checkSynonyms($data, $errors);
        $this->checkRelatedPatterns($data, $errors);
        $this->checkVars($data, $errors);
        $this->checkRequires($data, $errors);
        $this->checkFiles($data, $errors);
        $this->checkRegistrations($data, $errors);
        $this->checkRecipes($data, $errors);

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @param string[] $errors
     */
    private function checkString(array $data, string $field, array &$errors): void
    {
        if (isset($data[$field]) && !is_string($data[$field])) {
            $errors[] = "\"$field\" must be a string";
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param string[] $errors
     */
    private function checkStringArray(array $data, string $field, array &$errors): void
    {
        if (!isset($data[$field])) {
            return;
        }

        if (!is_array($data[$field])) {
            $errors[] = "\"$field\" must be an array of strings";
            return;
        }

        foreach ($data[$field] as $i => $item) {
            if (!is_string($item)) {
                $errors[] = "\"$field\"[$i] must be a string";
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param string[] $allowed
     * @param string[] $errors
     */
    private function checkEnum(array $data, string $field, array $allowed, array &$errors): void
    {
        if (!isset($data[$field])) {
            return;
        }

        if (!is_string($data[$field]) || !in_array($data[$field], $allowed, true)) {
            $errors[] = sprintf('"%s" must be one of: %s', $field, implode(', ', $allowed));
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param string[] $errors
     */
    private function checkSynonyms(array $data, array &$errors): void
    {
        if (!isset($data['synonyms'])) {
            return;
        }

        if (!is_array($data['synonyms'])) {
            $errors[] = '"synonyms" must be an object mapping domain names to arrays of strings';
            return;
        }

        foreach ($data['synonyms'] as $domain => $terms) {
            if (!is_string($domain)) {
                $errors[] = '"synonyms" keys must be strings (got non-string domain)';
                continue;
            }

            if (!is_array($terms)) {
                $errors[] = "\"synonyms.$domain\" must be an array of strings";
                continue;
            }

            foreach ($terms as $i => $term) {
                if (!is_string($term)) {
                    $errors[] = "\"synonyms.$domain\"[$i] must be a string";
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param string[] $errors
     */
    private function checkRelatedPatterns(array $data, array &$errors): void
    {
        if (!isset($data['relatedPatterns'])) {
            return;
        }

        if (!is_array($data['relatedPatterns'])) {
            $errors[] = '"relatedPatterns" must be an array';
            return;
        }

        foreach ($data['relatedPatterns'] as $i => $rel) {
            if (!is_array($rel)) {
                $errors[] = "\"relatedPatterns\"[$i] must be an object";
                continue;
            }

            if (!isset($rel['recipe']) || !is_string($rel['recipe'])) {
                $errors[] = "\"relatedPatterns\"[$i] missing required string \"recipe\"";
            }

            if (!isset($rel['relationship']) || !is_string($rel['relationship'])) {
                $errors[] = "\"relatedPatterns\"[$i] missing required string \"relationship\"";
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param string[] $errors
     */
    private function checkVars(array $data, array &$errors): void
    {
        if (!isset($data['vars'])) {
            return;
        }

        if (!is_array($data['vars'])) {
            $errors[] = '"vars" must be an object';
            return;
        }

        foreach ($data['vars'] as $name => $def) {
            if (!is_string($name)) {
                $errors[] = '"vars" keys must be strings';
                continue;
            }

            if (!is_array($def)) {
                $errors[] = "\"vars.$name\" must be an object";
                continue;
            }

            foreach (['type', 'description', 'example', 'aiHint'] as $strField) {
                if (isset($def[$strField]) && !is_string($def[$strField])) {
                    $errors[] = "\"vars.$name.$strField\" must be a string";
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param string[] $errors
     */
    private function checkRequires(array $data, array &$errors): void
    {
        if (!isset($data['requires'])) {
            return;
        }

        if (!is_array($data['requires'])) {
            $errors[] = '"requires" must be an array';
            return;
        }

        foreach ($data['requires'] as $i => $req) {
            if (!is_array($req)) {
                $errors[] = "\"requires\"[$i] must be an object";
                continue;
            }

            if (isset($req['type']) && !is_string($req['type'])) {
                $errors[] = "\"requires\"[$i].type must be a string";
            }

            if (isset($req['value']) && !is_string($req['value'])) {
                $errors[] = "\"requires\"[$i].value must be a string";
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param string[] $errors
     */
    private function checkFiles(array $data, array &$errors): void
    {
        if (!isset($data['files'])) {
            return;
        }

        if (!is_array($data['files'])) {
            $errors[] = '"files" must be an array';
            return;
        }

        foreach ($data['files'] as $i => $file) {
            if (!is_array($file)) {
                $errors[] = "\"files\"[$i] must be an object";
                continue;
            }

            if (!isset($file['path']) || !is_string($file['path'])) {
                $errors[] = "\"files\"[$i] missing required string \"path\"";
            }

            if (!isset($file['template']) || !is_string($file['template'])) {
                $errors[] = "\"files\"[$i] missing required string \"template\"";
            }

            if (isset($file['vars']) && !is_array($file['vars'])) {
                $errors[] = "\"files\"[$i].vars must be an object";
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param string[] $errors
     */
    private function checkRegistrations(array $data, array &$errors): void
    {
        if (!isset($data['registrations'])) {
            return;
        }

        if (!is_array($data['registrations'])) {
            $errors[] = '"registrations" must be an array';
            return;
        }

        foreach ($data['registrations'] as $i => $reg) {
            if (!is_array($reg)) {
                $errors[] = "\"registrations\"[$i] must be an object";
                continue;
            }

            foreach (['initializer', 'method', 'interface', 'type'] as $required) {
                if (!isset($reg[$required]) || !is_string($reg[$required])) {
                    $errors[] = "\"registrations\"[$i] missing required string \"$required\"";
                }
            }

            if (isset($reg['type']) && is_string($reg['type']) && !in_array($reg['type'], self::REGISTRATION_TYPES, true)) {
                $errors[] = sprintf('"registrations"[%d].type must be one of: %s', $i, implode(', ', self::REGISTRATION_TYPES));
            }

            foreach (['key', 'value'] as $optional) {
                if (isset($reg[$optional]) && !is_string($reg[$optional])) {
                    $errors[] = "\"registrations\"[$i].$optional must be a string";
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param string[] $errors
     */
    private function checkRecipes(array $data, array &$errors): void
    {
        if (!isset($data['recipes'])) {
            return;
        }

        if (!is_array($data['recipes'])) {
            $errors[] = '"recipes" must be an array';
            return;
        }

        foreach ($data['recipes'] as $i => $ref) {
            if (!is_array($ref)) {
                $errors[] = "\"recipes\"[$i] must be an object";
                continue;
            }

            if (!isset($ref['recipe']) || !is_string($ref['recipe'])) {
                $errors[] = "\"recipes\"[$i] missing required string \"recipe\"";
            }

            if (isset($ref['vars']) && !is_array($ref['vars'])) {
                $errors[] = "\"recipes\"[$i].vars must be an object";
            }
        }
    }
}
