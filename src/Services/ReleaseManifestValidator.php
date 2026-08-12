<?php

namespace Waadby\OperationsAgent\Services;

class ReleaseManifestValidator
{
    private const TOP_LEVEL = [
        'manifest_version', 'application_code', 'version', 'minimum_version', 'maximum_version',
        'source_commit', 'package_sha256', 'package_file', 'backup_required', 'maintenance_required',
        'requirements', 'database', 'configuration', 'healthchecks',
    ];

    private const REQUIRED = [
        'manifest_version', 'application_code', 'version', 'source_commit', 'package_sha256',
        'backup_required', 'maintenance_required', 'database', 'configuration', 'healthchecks',
    ];

    /** @return list<string> */
    public function errorsFromJson(string $json): array
    {
        $document = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        $manifest = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (! is_object($document) || ! is_array($manifest)) {
            return ['El release manifest debe contener un objeto JSON.'];
        }

        return array_values(array_unique([
            ...$this->jsonObjectShapeErrors($document),
            ...$this->errors($manifest),
        ]));
    }

    /** @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function errors(array $manifest): array
    {
        $errors = [];
        $this->rejectUnknown($manifest, self::TOP_LEVEL, 'manifest', $errors);
        $this->requireKeys($manifest, self::REQUIRED, 'manifest', $errors);

        if (($manifest['manifest_version'] ?? null) !== 1) {
            $errors[] = 'manifest_version debe ser 1.';
        }
        if (! is_string($manifest['application_code'] ?? null) || ! preg_match('/^[a-z0-9][a-z0-9._-]+$/', $manifest['application_code'])) {
            $errors[] = 'application_code no cumple el patron permitido.';
        }
        if (! is_string($manifest['version'] ?? null) || trim($manifest['version']) === '') {
            $errors[] = 'version debe ser una cadena no vacia.';
        }
        foreach (['minimum_version', 'maximum_version'] as $field) {
            if (array_key_exists($field, $manifest) && $manifest[$field] !== null && ! is_string($manifest[$field])) {
                $errors[] = "{$field} debe ser string o null.";
            }
        }
        if (! is_string($manifest['source_commit'] ?? null) || ! preg_match('/^[a-f0-9]{40}$/i', $manifest['source_commit'])) {
            $errors[] = 'source_commit debe contener exactamente 40 caracteres hexadecimales.';
        }
        if (! is_string($manifest['package_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/i', $manifest['package_sha256'])) {
            $errors[] = 'package_sha256 debe contener 64 caracteres hexadecimales.';
        }
        if (array_key_exists('package_file', $manifest) && (! is_string($manifest['package_file']) || ! $this->packageFileIsSafe($manifest['package_file']))) {
            $errors[] = 'package_file contiene una ruta insegura.';
        }
        foreach (['backup_required', 'maintenance_required'] as $field) {
            if (! is_bool($manifest[$field] ?? null)) {
                $errors[] = "{$field} debe ser booleano.";
            }
        }

        $this->validateRequirements($manifest['requirements'] ?? null, array_key_exists('requirements', $manifest), $errors);
        $this->validateDatabase($manifest['database'] ?? null, $errors);
        $this->validateConfiguration($manifest['configuration'] ?? null, $errors);
        $this->validateHealthchecks($manifest['healthchecks'] ?? null, $errors);

        return array_values(array_unique($errors));
    }

    /** @param list<string> $errors */
    private function validateRequirements(mixed $requirements, bool $present, array &$errors): void
    {
        if (! $present) {
            return;
        }
        if (! is_array($requirements)) {
            $errors[] = 'requirements debe ser un objeto.';

            return;
        }
        $this->rejectUnknown($requirements, ['php', 'extensions', 'database'], 'requirements', $errors);
        if (array_key_exists('php', $requirements) && ! is_string($requirements['php'])) {
            $errors[] = 'requirements.php debe ser string.';
        }
        if (array_key_exists('extensions', $requirements)) {
            $extensions = $requirements['extensions'];
            if (! is_array($extensions) || ! array_is_list($extensions) || array_filter($extensions, fn (mixed $value): bool => ! is_string($value)) !== []) {
                $errors[] = 'requirements.extensions debe ser un array de strings.';
            } elseif (count($extensions) !== count(array_unique($extensions, SORT_STRING))) {
                $errors[] = 'requirements.extensions no puede contener duplicados.';
            }
        }
        if (array_key_exists('database', $requirements)) {
            $database = $requirements['database'];
            if (! is_array($database)) {
                $errors[] = 'requirements.database debe ser un objeto.';

                return;
            }
            $this->rejectUnknown($database, ['driver', 'minimum_version'], 'requirements.database', $errors);
            if (array_key_exists('driver', $database) && ! is_string($database['driver'])) {
                $errors[] = 'requirements.database.driver debe ser string.';
            }
            if (array_key_exists('minimum_version', $database) && $database['minimum_version'] !== null && ! is_string($database['minimum_version'])) {
                $errors[] = 'requirements.database.minimum_version debe ser string o null.';
            }
        }
    }

    /** @param list<string> $errors */
    private function validateDatabase(mixed $database, array &$errors): void
    {
        if (! is_array($database)) {
            $errors[] = 'database debe ser un objeto.';

            return;
        }
        $this->rejectUnknown($database, ['migrations'], 'database', $errors);
        $this->requireKeys($database, ['migrations'], 'database', $errors);
        if (! is_bool($database['migrations'] ?? null)) {
            $errors[] = 'database.migrations debe ser booleano.';
        }
    }

    /** @param list<string> $errors */
    private function validateConfiguration(mixed $configuration, array &$errors): void
    {
        if (! is_array($configuration)) {
            $errors[] = 'configuration debe ser un objeto.';

            return;
        }
        $this->rejectUnknown($configuration, ['new_variables'], 'configuration', $errors);
        $this->requireKeys($configuration, ['new_variables'], 'configuration', $errors);
        $variables = $configuration['new_variables'] ?? null;
        if (! is_array($variables) || ! array_is_list($variables)) {
            $errors[] = 'configuration.new_variables debe ser un array.';

            return;
        }
        foreach ($variables as $index => $variable) {
            $prefix = "configuration.new_variables.{$index}";
            if (! is_array($variable)) {
                $errors[] = "{$prefix} debe ser un objeto.";

                continue;
            }
            $this->rejectUnknown($variable, ['name', 'required', 'sensitive', 'description', 'default'], $prefix, $errors);
            $this->requireKeys($variable, ['name', 'required', 'sensitive', 'description'], $prefix, $errors);
            if (! is_string($variable['name'] ?? null) || ! preg_match('/^[A-Z][A-Z0-9_]*$/', $variable['name'])) {
                $errors[] = "{$prefix}.name no es valido.";
            }
            foreach (['required', 'sensitive'] as $flag) {
                if (! is_bool($variable[$flag] ?? null)) {
                    $errors[] = "{$prefix}.{$flag} debe ser booleano.";
                }
            }
            if (! is_string($variable['description'] ?? null)) {
                $errors[] = "{$prefix}.description debe ser string.";
            }
            if (($variable['sensitive'] ?? false) === true && array_key_exists('default', $variable)) {
                $errors[] = "La variable sensible {$variable['name']} no puede declarar default.";
            }
        }
    }

    /** @param list<string> $errors */
    private function validateHealthchecks(mixed $healthchecks, array &$errors): void
    {
        if (! is_array($healthchecks) || ! array_is_list($healthchecks)) {
            $errors[] = 'healthchecks debe ser un array de rutas.';

            return;
        }
        foreach ($healthchecks as $index => $healthcheck) {
            if (! is_string($healthcheck) || ! str_starts_with($healthcheck, '/')) {
                $errors[] = "healthchecks.{$index} debe ser una ruta que comience por /.";
            }
        }
    }

    private function packageFileIsSafe(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, ':') || str_starts_with($path, '/')) {
            return false;
        }

        $segments = explode('/', $path);

        return ! in_array('', $segments, true) && ! in_array('.', $segments, true);
    }

    /** @return list<string> */
    private function jsonObjectShapeErrors(object $document): array
    {
        $errors = [];
        if (property_exists($document, 'requirements')) {
            if (! is_object($document->requirements)) {
                $errors[] = 'requirements debe ser un objeto.';
            } elseif (property_exists($document->requirements, 'database') && ! is_object($document->requirements->database)) {
                $errors[] = 'requirements.database debe ser un objeto.';
            }
            if (is_object($document->requirements) && property_exists($document->requirements, 'extensions') && ! is_array($document->requirements->extensions)) {
                $errors[] = 'requirements.extensions debe ser un array de strings.';
            }
        }
        if (property_exists($document, 'database') && ! is_object($document->database)) {
            $errors[] = 'database debe ser un objeto.';
        }
        if (property_exists($document, 'configuration')) {
            if (! is_object($document->configuration)) {
                $errors[] = 'configuration debe ser un objeto.';
            } elseif (property_exists($document->configuration, 'new_variables')) {
                if (! is_array($document->configuration->new_variables)) {
                    $errors[] = 'configuration.new_variables debe ser un array.';
                } else {
                    foreach ($document->configuration->new_variables as $index => $variable) {
                        if (! is_object($variable)) {
                            $errors[] = "configuration.new_variables.{$index} debe ser un objeto.";
                        }
                    }
                }
            }
        }
        if (property_exists($document, 'healthchecks') && ! is_array($document->healthchecks)) {
            $errors[] = 'healthchecks debe ser un array de rutas.';
        }

        return $errors;
    }

    /** @param array<array-key, mixed> $data
     * @param  list<string>  $allowed
     * @param  list<string>  $errors
     */
    private function rejectUnknown(array $data, array $allowed, string $path, array &$errors): void
    {
        foreach (array_keys($data) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                $errors[] = "{$path} contiene la propiedad desconocida {$key}.";
            }
        }
    }

    /** @param array<array-key, mixed> $data
     * @param  list<string>  $required
     * @param  list<string>  $errors
     */
    private function requireKeys(array $data, array $required, string $path, array &$errors): void
    {
        foreach ($required as $key) {
            if (! array_key_exists($key, $data)) {
                $errors[] = "Falta el campo obligatorio {$path}.{$key}.";
            }
        }
    }
}
