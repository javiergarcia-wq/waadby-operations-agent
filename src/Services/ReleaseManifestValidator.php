<?php

namespace Waadby\OperationsAgent\Services;

class ReleaseManifestValidator
{
    /** @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function errors(array $manifest): array
    {
        $errors = [];
        foreach (['manifest_version', 'application_code', 'version', 'source_commit', 'package_sha256', 'backup_required', 'maintenance_required', 'database', 'configuration', 'healthchecks'] as $required) {
            if (! array_key_exists($required, $manifest)) {
                $errors[] = "Falta el campo obligatorio {$required}.";
            }
        }
        if (($manifest['manifest_version'] ?? null) !== 1) {
            $errors[] = 'manifest_version debe ser 1.';
        }
        foreach (['application_code', 'version', 'source_commit', 'package_sha256'] as $stringField) {
            if (isset($manifest[$stringField]) && (! is_string($manifest[$stringField]) || trim($manifest[$stringField]) === '')) {
                $errors[] = "{$stringField} debe ser una cadena no vacia.";
            }
        }
        if (isset($manifest['package_sha256']) && (! is_string($manifest['package_sha256']) || ! preg_match('/^[a-f0-9]{64}$/i', $manifest['package_sha256']))) {
            $errors[] = 'package_sha256 debe contener 64 caracteres hexadecimales.';
        }
        foreach (['minimum_version', 'maximum_version'] as $versionField) {
            if (array_key_exists($versionField, $manifest) && $manifest[$versionField] !== null && ! is_string($manifest[$versionField])) {
                $errors[] = "{$versionField} debe ser string o null.";
            }
        }
        foreach (['backup_required', 'maintenance_required'] as $booleanField) {
            if (isset($manifest[$booleanField]) && ! is_bool($manifest[$booleanField])) {
                $errors[] = "{$booleanField} debe ser booleano.";
            }
        }
        if (isset($manifest['database']) && (! is_array($manifest['database']) || ! is_bool($manifest['database']['migrations'] ?? null))) {
            $errors[] = 'database.migrations debe ser booleano.';
        }
        $variables = $manifest['configuration']['new_variables'] ?? null;
        if (! is_array($variables)) {
            $errors[] = 'configuration.new_variables debe ser un array.';
        } else {
            foreach ($variables as $index => $variable) {
                if (! is_array($variable) || ! is_string($variable['name'] ?? null) || ! preg_match('/^[A-Z][A-Z0-9_]*$/', $variable['name'])) {
                    $errors[] = "configuration.new_variables.{$index}.name no es valido.";

                    continue;
                }
                foreach (['required', 'sensitive'] as $flag) {
                    if (! is_bool($variable[$flag] ?? null)) {
                        $errors[] = "configuration.new_variables.{$index}.{$flag} debe ser booleano.";
                    }
                }
                if (($variable['sensitive'] ?? false) === true && array_key_exists('default', $variable) && $variable['default'] !== null) {
                    $errors[] = "La variable sensible {$variable['name']} no puede declarar default.";
                }
            }
        }
        if (isset($manifest['healthchecks']) && (! is_array($manifest['healthchecks']) || array_filter($manifest['healthchecks'], fn (mixed $value): bool => ! is_string($value)) !== [])) {
            $errors[] = 'healthchecks debe ser un array de rutas.';
        }

        return array_values(array_unique($errors));
    }
}
