<?php

namespace Waadby\OperationsAgent\Services;

use RuntimeException;
use Waadby\OperationsAgent\Contracts\OperationsReporter;

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
            $warnings[] = 'El backup procede de una version posterior a la instalada actualmente.';
        }

        return [
            'compatible' => $blockers === [],
            'application_code' => $manifest['application_code'],
            'backup_version' => $manifest['application_version'],
            'current_version' => (string) config('waadby_operations.application.version'),
            'database_driver' => $backupDriver,
            'configured_database_driver' => $configuredDriver,
            'backup_type' => $manifest['backup_type'],
            'configuration_available' => (bool) ($manifest['configuration']['included'] ?? false),
            'checksum_state' => 'valid',
            'verification_source' => $verificationSource,
            'warnings' => $warnings,
            'blockers' => $blockers,
            'data_modified' => false,
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
