<?php

namespace Waadby\OperationsAgent\Restores;

use RuntimeException;
use Waadby\OperationsAgent\Contracts\OperationsReporter;
use Waadby\OperationsAgent\Contracts\RestoreLifecycleHooks;
use Waadby\OperationsAgent\Services\BackupVerifier;
use Waadby\OperationsAgent\Services\RestorePreflightService;

final class RestoreExecutor
{
    public function __construct(
        private readonly RestorePreflightService $preflight,
        private readonly BackupVerifier $verifier,
        private readonly RestoreArchiveStage $archives,
        private readonly RestoreApplier $applier,
        private readonly RestoreJournalStore $journals,
        private readonly RestoreLifecycleHooks $lifecycle,
        private readonly OperationsReporter $reporter,
    ) {}

    /** @param array<string, mixed> $plan @param array<string, mixed> $context @return array<string, mixed> */
    public function execute(array $plan, string $sourcePath, string $safetyBackupPath, array $context = []): array
    {
        if (! config('waadby_operations.restores.apply_enabled', false)) {
            throw new RuntimeException('RESTORE APPLY esta desactivado por feature flag.');
        }
        RestorePlan::validate($plan);
        $restoreId = (string) $plan['plan_id'];
        $journal = $this->existingOrBegin($plan);
        if (($journal['status'] ?? null) === 'succeeded') {
            return ['restore_id' => $restoreId, 'status' => 'succeeded', 'idempotent' => true];
        }
        $sourceStage = null;
        $safetyStage = null;
        $pointOfNoReturn = false;
        try {
            $this->assertSafety($plan, $safetyBackupPath, $context);
            $this->journals->checkpoint($restoreId, 'safety_backup_verified', 'validating_source');
            $sourceInspection = $this->verifier->inspect($sourcePath, (string) $plan['target']['application_code'], (string) $plan['source']['backup_id'], (string) $plan['source']['sha256']);
            $safetyInspection = $safetyBackupPath !== '' ? $this->verifier->inspect($safetyBackupPath, (string) $plan['target']['application_code']) : null;
            $this->assertEnvironment($sourceInspection['manifest'], (string) $plan['target']['environment']);
            if (is_array($safetyInspection)) {
                $this->assertEnvironment($safetyInspection['manifest'], (string) $plan['target']['environment']);
            }
            $sourceStage = $this->archives->extract($sourcePath, $restoreId.'-source');
            $safetyStage = $safetyBackupPath !== '' ? $this->archives->extract($safetyBackupPath, $restoreId.'-safety') : null;
            $this->applier->validate($sourceStage, $sourceInspection['manifest']);
            if (is_string($safetyStage) && is_array($safetyInspection)) {
                $this->applier->validate($safetyStage, $safetyInspection['manifest']);
            }
            $this->preflight->analyze($sourcePath, null, $context['actor_id'] ?? null, true);
            RestorePlan::validate($plan);
            if (! hash_equals((string) $plan['source']['sha256'], hash_file('sha256', $sourcePath))) {
                throw new RuntimeException('El origen restore cambio antes del punto de no retorno.');
            }
            ($context['hold_integrations'] ?? static function (): void {})($restoreId);
            $this->reporter->audit('operations.restore.integration_hold_enabled', ['restore_id' => $restoreId, 'result' => 'enabled']);
            $this->reporter->audit('operations.restore.started', ['restore_id' => $restoreId, 'result' => 'started']);
            $this->lifecycle->quiesce();
            $this->journals->checkpoint($restoreId, 'point_of_no_return', 'applying', ['safety_sha256' => $safetyBackupPath !== '' ? hash_file('sha256', $safetyBackupPath) : null, 'disaster_mode' => $safetyBackupPath === ''], true);
            $pointOfNoReturn = true;
            $this->reporter->audit('operations.restore.point_of_no_return', ['restore_id' => $restoreId, 'result' => 'entered']);
            $this->applier->apply($sourceStage, $sourceInspection['manifest']);
            if (($sourceInspection['manifest']['database']['included'] ?? false) === true) {
                $this->reporter->audit('operations.restore.database_restored', ['restore_id' => $restoreId, 'result' => 'restored']);
            }
            if (($sourceInspection['manifest']['storage']['included'] ?? false) === true) {
                $this->reporter->audit('operations.restore.storage_restored', ['restore_id' => $restoreId, 'result' => 'restored']);
            }
            $this->journals->checkpoint($restoreId, 'data_applied', 'migrating');
            $this->lifecycle->migrateForward();
            $this->reporter->audit('operations.restore.migrations_completed', ['restore_id' => $restoreId, 'result' => 'forward_only']);
            $this->lifecycle->smokeInternal();
            $this->journals->checkpoint($restoreId, 'internal_smoke', 'healthchecking');
            $this->lifecycle->resume();
            try {
                $this->lifecycle->smokeHttp();
                $this->reporter->audit('operations.restore.healthcheck', ['restore_id' => $restoreId, 'result' => 'healthy']);
            } catch (\Throwable $httpFailure) {
                $this->lifecycle->quiesce();
                throw $httpFailure;
            }
            $this->journals->checkpoint($restoreId, 'completed', 'succeeded');
            $this->reporter->audit('operations.restore.succeeded', ['restore_id' => $restoreId, 'result' => 'succeeded']);

            return ['restore_id' => $restoreId, 'status' => 'succeeded', 'plan_sha256' => $plan['plan_sha256']];
        } catch (\Throwable $failure) {
            if (! $pointOfNoReturn) {
                $this->journals->checkpoint($restoreId, 'failed_before_apply', 'failed', ['error' => $this->safe($failure)]);
                $this->reporter->audit('operations.restore.failed', ['restore_id' => $restoreId, 'result' => 'failed']);
                throw $failure;
            }
            if (! is_string($safetyStage)) {
                $this->journals->checkpoint($restoreId, 'no_safety_rollback', 'recovery_required', ['cause' => $this->safe($failure)]);
                $this->reporter->audit('operations.restore.recovery_required', ['restore_id' => $restoreId, 'result' => 'recovery_required', 'disaster_mode' => true]);
                throw new RuntimeException('Disaster restore fallo sin safety backup; se requiere recuperacion manual.', previous: $failure);
            }
            $this->reporter->audit('operations.restore.rollback_started', ['restore_id' => $restoreId, 'result' => 'started']);
            try {
                $safetyInspection ??= $this->verifier->inspect($safetyBackupPath, (string) $plan['target']['application_code']);
                $this->applier->apply($safetyStage, $safetyInspection['manifest']);
                $this->lifecycle->migrateForward();
                $this->lifecycle->smokeInternal();
                $this->lifecycle->resume();
                $this->lifecycle->smokeHttp();
                $this->journals->checkpoint($restoreId, 'rollback_completed', 'rolled_back', ['cause' => $this->safe($failure)]);
                $this->reporter->audit('operations.restore.rolled_back', ['restore_id' => $restoreId, 'result' => 'rolled_back']);

                return ['restore_id' => $restoreId, 'status' => 'rolled_back', 'failure_message_safe' => $this->safe($failure)];
            } catch (\Throwable $rollbackFailure) {
                $this->journals->checkpoint($restoreId, 'rollback_failed', 'recovery_required', ['cause' => $this->safe($failure), 'rollback_error' => $this->safe($rollbackFailure)]);
                $this->reporter->audit('operations.restore.recovery_required', ['restore_id' => $restoreId, 'result' => 'recovery_required']);
                throw new RuntimeException('Restore y rollback fallaron; se requiere recuperacion manual mediante el journal privado.', previous: $rollbackFailure);
            }
        } finally {
            $this->archives->cleanup($sourceStage);
            $this->archives->cleanup($safetyStage);
        }
    }

    /** @param array<string, mixed> $plan @param array<string, mixed> $context */
    private function assertSafety(array $plan, string $safetyBackupPath, array $context): void
    {
        $disaster = (bool) ($context['disaster_mode'] ?? false);
        if ($safetyBackupPath === '' && ! $disaster) {
            throw new RuntimeException('Restore exige un safety backup especifico y VERIFIED.');
        }
        if (! $disaster && (string) $plan['target']['environment'] === 'production' && ! (bool) ($context['safety_vault_verified'] ?? false)) {
            throw new RuntimeException('Produccion exige VaultReplica VERIFIED del safety backup.');
        }
        if ($disaster && (! filled($context['reason'] ?? null) || ! (bool) ($context['acknowledge_no_safety_backup'] ?? false))) {
            throw new RuntimeException('Disaster mode exige reason y acknowledgement explicitos.');
        }
    }

    /** @param array<string, mixed> $manifest */
    private function assertEnvironment(array $manifest, string $environment): void
    {
        if (! hash_equals($environment, (string) ($manifest['environment'] ?? ''))) {
            throw new RuntimeException('El environment del backup no coincide con el destino restore.');
        }
    }

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    private function existingOrBegin(array $plan): array
    {
        try {
            $journal = $this->journals->read((string) $plan['plan_id']);
            if (! hash_equals((string) $journal['plan']['plan_sha256'], (string) $plan['plan_sha256'])) {
                throw new RuntimeException('Ya existe un restore_id con otro plan.');
            }

            return $journal;
        } catch (RuntimeException $e) {
            if (! str_contains($e->getMessage(), 'no existe')) {
                throw $e;
            }

            return $this->journals->begin($plan);
        }
    }

    private function safe(\Throwable $exception): string
    {
        return mb_substr(preg_replace('/(?:password|secret|token|key)\s*[=:]\s*\S+/i', '$1=[REDACTED]', $exception->getMessage()) ?: 'restore_failed', 0, 500);
    }
}
