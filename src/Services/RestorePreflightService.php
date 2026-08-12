<?php

namespace Waadby\OperationsAgent\Services;

use RuntimeException;
use Waadby\OperationsAgent\Contracts\OperationsReporter;

class RestorePreflightService
{
    public function __construct(
        private readonly BackupVerifier $verifier,
        private readonly OperationsReporter $reporter,
    ) {}

    /** @return array<string, mixed> */
    public function analyze(string $reference, ?string $idempotencyKey = null, ?int $actorId = null): array
    {
        $artifact = $this->reporter->findArtifact($reference);
        if (! $artifact) {
            throw new RuntimeException('No se encontro el backup solicitado.');
        }
        if (($artifact['status'] ?? null) !== 'verified') {
            throw new RuntimeException('Restore preflight exige un backup con estado VERIFIED.');
        }

        $operation = $this->reporter->beginOperation('restore_preflight', $idempotencyKey, $actorId);
        $blockers = [];
        $warnings = [];
        try {
            $inspection = $this->verifier->inspect(
                (string) ($artifact['absolute_path'] ?? $artifact['storage_path']),
                (string) config('waadby_operations.application.code'),
                (string) $artifact['public_id'],
                $artifact['sha256'] ?? null,
            );
            $manifest = $inspection['manifest'];
            if (($manifest['database']['driver'] ?? null) !== app('db')->connection()->getDriverName()) {
                $blockers[] = 'El driver de base de datos del backup no coincide con la instalacion actual.';
            }
            if (($manifest['configuration']['included'] ?? false) !== true) {
                $warnings[] = 'El backup no incluye configuracion cifrada; la recuperacion requerira configuracion externa.';
            }
            if (version_compare((string) $manifest['application_version'], (string) config('waadby_operations.application.version'), '>')) {
                $warnings[] = 'El backup procede de una version posterior a la instalada actualmente.';
            }

            $result = [
                'compatible' => $blockers === [],
                'application_code' => $manifest['application_code'],
                'backup_version' => $manifest['application_version'],
                'current_version' => (string) config('waadby_operations.application.version'),
                'database_driver' => $manifest['database']['driver'] ?? null,
                'backup_type' => $manifest['backup_type'],
                'configuration_available' => (bool) ($manifest['configuration']['included'] ?? false),
                'checksum_state' => 'valid',
                'warnings' => $warnings,
                'blockers' => $blockers,
                'data_modified' => false,
            ];
            $this->reporter->finishOperation($operation['public_id'], $result['compatible'] ? 'succeeded' : 'failed', $result, $result['compatible'] ? null : 'restore_incompatible', $blockers[0] ?? null);
            $this->reporter->audit('operations.restore_preflight.executed', ['operation_public_id' => $operation['public_id'], 'backup_public_id' => $artifact['public_id'], 'result' => $result['compatible'] ? 'compatible' : 'incompatible']);

            return $result;
        } catch (\Throwable $exception) {
            $this->reporter->finishOperation($operation['public_id'], 'failed', [], 'restore_preflight_failed', mb_substr($exception->getMessage(), 0, 500));
            $this->reporter->audit('operations.restore_preflight.executed', ['operation_public_id' => $operation['public_id'], 'backup_public_id' => $artifact['public_id'], 'result' => 'failed']);
            throw $exception;
        }
    }
}
