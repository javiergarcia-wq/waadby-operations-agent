<?php

namespace Waadby\OperationsAgent\Services;

use RuntimeException;
use Waadby\OperationsAgent\Contracts\OperationsReporter;
use Waadby\OperationsAgent\Restores\RestorePlan;

class RestorePreflightService
{
    public function __construct(
        private readonly BackupVerifier $verifier,
        private readonly DatabaseRuntimeInfo $databaseInfo,
        private readonly OperationsReporter $reporter,
    ) {}

    /** @return array<string, mixed> */
    public function analyze(string $reference, ?string $idempotencyKey = null, ?int $actorId = null, bool $allowPortable = false): array
    {
        if (is_file($reference)) {
            if (! $allowPortable) {
                throw new RuntimeException('Las rutas directas de backup solo estan permitidas en el CLI local.');
            }

            $inspection = $this->verifier->inspect($reference, (string) config('waadby_operations.application.code'));
            $result = $this->assess($inspection, 'portable_inline');
            $this->reportPortable($result, $idempotencyKey, $actorId);

            return $result;
        }

        return $this->analyzePersisted($reference, $idempotencyKey, $actorId);
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    public function plan(string $reference, array $source, ?int $actorId = null, bool $allowPortable = false): array
    {
        $result = $this->analyze($reference, null, $actorId, $allowPortable);
        if (! $result['compatible']) {
            throw new RuntimeException($result['blockers'][0] ?? 'El backup no es compatible con la instalacion.');
        }

        return RestorePlan::create($result, $source, (int) config('waadby_operations.restores.plan_ttl_seconds', 900));
    }

    /** @return array<string, mixed> */
    private function analyzePersisted(string $reference, ?string $idempotencyKey, ?int $actorId): array
    {
        $artifact = $this->reporter->findArtifact($reference);
        if (! $artifact) {
            throw new RuntimeException('No se encontro el backup solicitado.');
        }
        if (($artifact['status'] ?? null) !== 'verified') {
            throw new RuntimeException('Restore preflight exige un backup con estado VERIFIED.');
        }

        $operation = $this->reporter->beginOperation('restore_preflight', $idempotencyKey, $actorId);
        try {
            $inspection = $this->verifier->inspect(
                (string) ($artifact['absolute_path'] ?? $artifact['storage_path']),
                (string) config('waadby_operations.application.code'),
                (string) $artifact['public_id'],
                $artifact['sha256'] ?? null,
            );
            $result = $this->assess($inspection, 'persisted_verified');
            $this->reporter->finishOperation($operation['public_id'], $result['compatible'] ? 'succeeded' : 'failed', $result, $result['compatible'] ? null : 'restore_incompatible', $result['blockers'][0] ?? null);
            $this->reporter->audit('operations.restore_preflight.executed', [
                'operation_public_id' => $operation['public_id'],
                'backup_public_id' => $artifact['public_id'],
                'verification_source' => 'persisted_verified',
                'result' => $result['compatible'] ? 'compatible' : 'incompatible',
            ]);

            return $result;
        } catch (\Throwable $exception) {
            $this->reporter->finishOperation($operation['public_id'], 'failed', [], 'restore_preflight_failed', mb_substr($exception->getMessage(), 0, 500));
            $this->reporter->audit('operations.restore_preflight.executed', ['operation_public_id' => $operation['public_id'], 'backup_public_id' => $artifact['public_id'], 'result' => 'failed']);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $inspection
     * @return array<string, mixed>
     */
    private function assess(array $inspection, string $verificationSource): array
    {
        $manifest = $inspection['manifest'];
        $blockers = [];
        $warnings = [];
        $backupDriver = $manifest['database']['driver'] ?? null;
        $configuredDriver = $this->databaseInfo->configuredDriver();
        $migration = $this->migrationCompatibility((array) ($manifest['migrations'] ?? []));
        $configuredEnvironment = (string) config('waadby_operations.application.environment');
        if (! hash_equals($configuredEnvironment, (string) ($manifest['environment'] ?? ''))) {
            $blockers[] = 'El environment del backup no coincide exactamente con la instalacion actual.';
        }
        if (($manifest['database']['included'] ?? false) === true) {
            if (! is_string($configuredDriver) || $configuredDriver === '') {
                $blockers[] = 'No se pudo determinar el driver de base de datos configurado en la instalacion.';
            } elseif ($backupDriver !== $configuredDriver) {
                $blockers[] = 'El driver de base de datos del backup no coincide con la instalacion actual.';
            }
        }
        if (($manifest['configuration']['included'] ?? false) !== true) {
            $warnings[] = 'El backup no incluye configuracion cifrada; la recuperacion requerira configuracion externa.';
        }
        if (version_compare((string) $manifest['application_version'], (string) config('waadby_operations.application.version'), '>')) {
            $blockers[] = 'El backup procede de una version posterior a la instalada actualmente.';
        } elseif (version_compare((string) $manifest['application_version'], (string) config('waadby_operations.application.version'), '<')
            && ! $migration['compatible']) {
            $blockers[] = 'El backup es anterior y no existe una ruta de migracion forward compatible demostrable.';
        }
        if (! $migration['compatible'] && ! in_array('El backup es anterior y no existe una ruta de migracion forward compatible demostrable.', $blockers, true)) {
            $blockers[] = 'La baseline de migrations del backup no es un prefijo demostrable del arbol disponible.';
        }

        return [
            'compatible' => $blockers === [],
            'application_code' => $manifest['application_code'],
            'environment' => $manifest['environment'],
            'backup_version' => $manifest['application_version'],
            'current_version' => (string) config('waadby_operations.application.version'),
            'database_driver' => $backupDriver,
            'configured_database_driver' => $configuredDriver,
            'backup_type' => $manifest['backup_type'],
            'backup_id' => $manifest['backup_id'],
            'backup_sha256' => $inspection['sha256'],
            'backup_size_bytes' => $inspection['size_bytes'],
            'manifest' => $manifest,
            'components' => [
                'database' => (bool) ($manifest['database']['included'] ?? false),
                'storage' => (bool) ($manifest['storage']['included'] ?? false),
                'configuration' => false,
                'code_snapshot' => false,
            ],
            'migration_baseline' => $manifest['migrations'] ?? ['count' => 0, 'last' => null],
            'target_migration_state' => $migration['target'],
            'forward_migrations' => $migration['forward'],
            'configuration_available' => (bool) ($manifest['configuration']['included'] ?? false),
            'checksum_state' => 'valid',
            'verification_source' => $verificationSource,
            'warnings' => $warnings,
            'blockers' => $blockers,
            'data_modified' => false,
        ];
    }

    /** @param array<string, mixed> $baseline @return array{compatible:bool,target:array<string,mixed>,forward:array<int,string>} */
    private function migrationCompatibility(array $baseline): array
    {
        $current = $this->databaseInfo->migrationState();
        $available = array_values($current['available_names'] ?? []);
        $count = filter_var($baseline['count'] ?? null, FILTER_VALIDATE_INT);
        $last = $baseline['last'] ?? null;
        $compatible = $count !== false && $count >= 0 && $count <= count($available)
            && (($count === 0 && $last === null)
                || ($count > 0 && is_string($last) && isset($available[$count - 1]) && hash_equals($available[$count - 1], $last)));

        return [
            'compatible' => $compatible,
            'target' => $this->databaseInfo->migrationSnapshot(),
            'forward' => $compatible ? array_slice($available, $count) : [],
        ];
    }

    /** @param array<string, mixed> $result */
    private function reportPortable(array $result, ?string $idempotencyKey, ?int $actorId): void
    {
        try {
            $operation = $this->reporter->beginOperation('restore_preflight', $idempotencyKey, $actorId);
            try {
                $this->reporter->finishOperation($operation['public_id'], $result['compatible'] ? 'succeeded' : 'failed', $result, $result['compatible'] ? null : 'restore_incompatible', $result['blockers'][0] ?? null);
            } catch (\Throwable) {
                // Offline recovery does not require operation persistence.
            }
            try {
                $this->reporter->audit('operations.restore_preflight.executed', [
                    'operation_public_id' => $operation['public_id'],
                    'backup_public_id' => null,
                    'verification_source' => 'portable_inline',
                    'result' => $result['compatible'] ? 'compatible' : 'incompatible',
                ]);
            } catch (\Throwable) {
                // Audit is best-effort only in portable mode.
            }
        } catch (\Throwable) {
            // No OperationRun or BackupArtifact is required in portable mode.
        }
    }
}
