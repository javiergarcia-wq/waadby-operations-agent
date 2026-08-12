<?php

namespace Waadby\OperationsAgent\Services;

use Waadby\OperationsAgent\Contracts\OperationsReporter;
use Waadby\OperationsAgent\Contracts\OperationsRuntime;

class LocalOperationsRuntime implements OperationsRuntime
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly BackupService $backupService,
        private readonly BackupVerifier $backupVerifier,
        private readonly RestorePreflightService $restorePreflightService,
        private readonly UpdatePreflightService $updatePreflightService,
        private readonly OperationsReporter $reporter,
    ) {}

    public function registerSelf(): array
    {
        return $this->reporter->installation();
    }

    public function inventory(bool $persist = true, ?string $idempotencyKey = null): array
    {
        $operation = $this->reporter->beginOperation('inventory', $idempotencyKey);
        $result = $this->inventoryService->collect();
        $this->reporter->finishOperation($operation['public_id'], 'succeeded', $result);
        $this->reporter->audit('operations.inventory.collected', ['operation_public_id' => $operation['public_id'], 'result' => 'succeeded']);

        return $result;
    }

    public function backup(string $type = 'operational', ?string $idempotencyKey = null, ?int $actorId = null): array
    {
        return $this->backupService->create($type, $idempotencyKey, $actorId);
    }

    public function verify(string $reference, ?string $idempotencyKey = null, ?int $actorId = null, bool $allowPortable = false): array
    {
        return $this->backupVerifier->verify($reference, $idempotencyKey, $actorId, $allowPortable);
    }

    public function restorePreflight(string $reference, ?string $idempotencyKey = null, ?int $actorId = null, bool $allowPortable = false): array
    {
        return $this->restorePreflightService->analyze($reference, $idempotencyKey, $actorId, $allowPortable);
    }

    public function updatePreflight(string $manifest, ?string $idempotencyKey = null, ?int $actorId = null): array
    {
        return $this->updatePreflightService->analyze($manifest, $idempotencyKey, $actorId);
    }
}
