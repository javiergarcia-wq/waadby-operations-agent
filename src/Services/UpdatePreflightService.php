<?php

namespace Waadby\OperationsAgent\Services;

use Illuminate\Support\Env;
use RuntimeException;
use Waadby\OperationsAgent\Contracts\OperationsReporter;

class UpdatePreflightService
{
    public function __construct(
        private readonly ReleaseManifestValidator $validator,
        private readonly OperationsReporter $reporter,
    ) {}

    /** @return array<string, mixed> */
    public function analyze(string $manifestPath, ?string $idempotencyKey = null, ?int $actorId = null): array
    {
        $operation = $this->reporter->beginOperation('update_preflight', $idempotencyKey, $actorId);
        try {
            $manifest = $this->read($manifestPath);
            $blockers = $this->validator->errors($manifest);
            $warnings = [];
            $currentVersion = (string) config('waadby_operations.application.version');
            if (($manifest['application_code'] ?? null) !== config('waadby_operations.application.code')) {
                $blockers[] = 'El application_code del release no coincide con la aplicacion instalada.';
            }
            if (is_string($manifest['minimum_version'] ?? null) && version_compare($currentVersion, $manifest['minimum_version'], '<')) {
                $blockers[] = "La version instalada es anterior a la minima {$manifest['minimum_version']}.";
            }
            if (is_string($manifest['maximum_version'] ?? null) && version_compare($currentVersion, $manifest['maximum_version'], '>')) {
                $blockers[] = "La version instalada supera la maxima {$manifest['maximum_version']}.";
            }

            $requirements = is_array($manifest['requirements'] ?? null) ? $manifest['requirements'] : [];
            if (is_string($requirements['php'] ?? null) && ! $this->phpMatches($requirements['php'])) {
                $blockers[] = 'PHP '.PHP_VERSION." no satisface {$requirements['php']}.";
            }
            foreach (($requirements['extensions'] ?? []) as $extension) {
                if (is_string($extension) && ! extension_loaded($extension)) {
                    $blockers[] = "Falta la extension PHP obligatoria {$extension}.";
                }
            }
            $database = is_array($requirements['database'] ?? null) ? $requirements['database'] : [];
            $currentDriver = app('db')->connection()->getDriverName();
            if (is_string($database['driver'] ?? null) && $database['driver'] !== $currentDriver) {
                $blockers[] = "El release requiere database.driver={$database['driver']}.";
            }

            $newConfiguration = [];
            foreach (($manifest['configuration']['new_variables'] ?? []) as $variable) {
                if (! is_array($variable) || ! is_string($variable['name'] ?? null)) {
                    continue;
                }
                $present = Env::get($variable['name']) !== null;
                if (! $present) {
                    $newConfiguration[] = [
                        'name' => $variable['name'],
                        'required' => (bool) ($variable['required'] ?? false),
                        'sensitive' => (bool) ($variable['sensitive'] ?? false),
                        'description' => (string) ($variable['description'] ?? ''),
                        'default_available' => array_key_exists('default', $variable) && $variable['default'] !== null,
                    ];
                    if (($variable['required'] ?? false) === true && ! array_key_exists('default', $variable)) {
                        $blockers[] = "Falta la nueva variable obligatoria {$variable['name']}.";
                    }
                }
            }

            if (is_string($manifest['package_file'] ?? null)) {
                $package = $this->resolvePackagePath($manifestPath, $manifest['package_file']);
                if (! is_file($package)) {
                    $blockers[] = 'No se encontro el paquete local declarado para verificar su checksum.';
                } elseif (is_string($manifest['package_sha256'] ?? null) && ! hash_equals(strtolower($manifest['package_sha256']), hash_file('sha256', $package))) {
                    $blockers[] = 'El checksum del paquete de release no coincide.';
                }
            } else {
                $warnings[] = 'El checksum del paquete no se verifico porque el manifest no declara package_file local.';
            }

            $result = [
                'compatible' => $blockers === [],
                'schema_valid' => $this->validator->errors($manifest) === [],
                'current_version' => $currentVersion,
                'target_version' => $manifest['version'] ?? null,
                'warnings' => array_values(array_unique($warnings)),
                'blockers' => array_values(array_unique($blockers)),
                'new_configuration_required' => $newConfiguration,
                'backup_required' => (bool) ($manifest['backup_required'] ?? false),
                'migrations_expected' => (bool) ($manifest['database']['migrations'] ?? false),
                'healthchecks' => $manifest['healthchecks'] ?? [],
                'system_modified' => false,
            ];
            $this->reporter->finishOperation($operation['public_id'], $result['compatible'] ? 'succeeded' : 'failed', $result, $result['compatible'] ? null : 'update_incompatible', $result['blockers'][0] ?? null);
            $this->reporter->audit('operations.update_preflight.executed', ['operation_public_id' => $operation['public_id'], 'result' => $result['compatible'] ? 'compatible' : 'incompatible']);

            return $result;
        } catch (\Throwable $exception) {
            $this->reporter->finishOperation($operation['public_id'], 'failed', [], 'update_preflight_failed', mb_substr($exception->getMessage(), 0, 500));
            $this->reporter->audit('operations.update_preflight.executed', ['operation_public_id' => $operation['public_id'], 'result' => 'failed']);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('No se encontro el release manifest solicitado.');
        }
        try {
            $manifest = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('El release manifest no contiene JSON valido.');
        }
        if (! is_array($manifest)) {
            throw new RuntimeException('El release manifest debe contener un objeto JSON.');
        }

        return $manifest;
    }

    private function phpMatches(string $constraint): bool
    {
        if (preg_match('/^(>=|>|<=|<|=|==)?\s*([0-9]+(?:\.[0-9]+){0,2})$/', trim($constraint), $matches)) {
            return version_compare(PHP_VERSION, $matches[2], $matches[1] ?: '>=');
        }

        return false;
    }

    private function resolvePackagePath(string $manifestPath, string $packageFile): string
    {
        if (str_contains($packageFile, '..') || str_starts_with(str_replace('\\', '/', $packageFile), '/') || preg_match('/^[A-Za-z]:/', $packageFile)) {
            throw new RuntimeException('package_file contiene una ruta insegura.');
        }

        return dirname(realpath($manifestPath) ?: $manifestPath).DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $packageFile);
    }
}
