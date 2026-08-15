<?php

namespace Waadby\OperationsAgent\Restores;

use Illuminate\Support\Str;
use RuntimeException;
use Waadby\OperationsAgent\Support\OperationsPrivateStoragePathPolicy;

final class RestoreJournalStore
{
    public function __construct(private readonly OperationsPrivateStoragePathPolicy $privateStorage) {}

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    public function begin(array $plan, array $projection = []): array
    {
        RestorePlan::validate($plan);
        $id = (string) $plan['plan_id'];
        $projection = $this->projection($plan, $projection);
        $journal = ['journal_version' => 1, 'restore_id' => $id, 'sequence' => 0, 'status' => 'planned', 'point_of_no_return' => false, 'plan' => $plan, 'projection' => $projection, 'checkpoints' => [], 'created_at' => now()->utc()->toIso8601String(), 'updated_at' => now()->utc()->toIso8601String()];
        $this->write($id, $journal);

        return $journal;
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    public function checkpoint(string $id, string $phase, string $status, array $metadata = [], bool $pointOfNoReturn = false): array
    {
        $journal = $this->read($id);
        if (in_array($journal['status'], ['succeeded', 'rolled_back', 'failed'], true)) {
            throw new RuntimeException('El journal restore ya esta en estado terminal.');
        }
        $journal['sequence']++;
        $journal['status'] = $status;
        $journal['point_of_no_return'] = $journal['point_of_no_return'] || $pointOfNoReturn;
        $journal['checkpoints'][] = ['sequence' => $journal['sequence'], 'phase' => $phase, 'status' => $status, 'at' => now()->utc()->toIso8601String(), 'metadata' => $metadata];
        $journal['updated_at'] = now()->utc()->toIso8601String();
        $this->write($id, $journal);

        return $journal;
    }

    /** @return array<string, mixed> */
    public function read(string $id): array
    {
        $path = $this->path($id);
        if (! is_file($path)) {
            throw new RuntimeException('El journal restore no existe.');
        }
        try {
            $document = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RuntimeException('El journal restore esta corrupto; se requiere recuperacion manual.', previous: $e);
        }
        $hash = (string) ($document['journal_sha256'] ?? '');
        unset($document['journal_sha256']);
        if (! preg_match('/^[a-f0-9]{64}$/', $hash) || ! hash_equals($hash, $this->hash($document))) {
            throw new RuntimeException('El journal restore esta corrupto; se requiere recuperacion manual.');
        }

        return $document;
    }

    /** @return list<array<string, mixed>> */
    public function unfinished(): array
    {
        $root = $this->privateStorage->prepareDirectory((string) config('waadby_operations.restores.journal_path'));
        $items = [];
        foreach (glob($root.DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
            try {
                $journal = $this->read(pathinfo($path, PATHINFO_FILENAME));
                if (! in_array($journal['status'], ['succeeded', 'rolled_back', 'failed', 'cancelled'], true)) {
                    $items[] = $journal;
                }
            } catch (\Throwable) {
                $items[] = ['restore_id' => pathinfo($path, PATHINFO_FILENAME), 'status' => 'recovery_required', 'journal_corrupt' => true];
            }
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $root = $this->privateStorage->prepareDirectory((string) config('waadby_operations.restores.journal_path'));
        $items = [];
        foreach (glob($root.DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
            try {
                $items[] = $this->read(pathinfo($path, PATHINFO_FILENAME));
            } catch (\Throwable) {
                $items[] = ['restore_id' => pathinfo($path, PATHINFO_FILENAME), 'status' => 'recovery_required', 'journal_corrupt' => true];
            }
        }

        return $items;
    }

    /** @param array<string, mixed> $journal */
    private function write(string $id, array $journal): void
    {
        $path = $this->path($id);
        $document = $journal;
        $document['journal_sha256'] = $this->hash($journal);
        $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
            throw new RuntimeException('No se pudo persistir el journal restore.');
        }
        $this->privateStorage->protectFile($temporary);
        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo publicar atomicamente el journal restore.');
        }
        $this->privateStorage->protectFile($path);
    }

    private function path(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('restore_id no es valido.');
        }
        $root = $this->privateStorage->prepareDirectory((string) config('waadby_operations.restores.journal_path'));

        return $this->privateStorage->assertFileWithinRoot($root, $root.DIRECTORY_SEPARATOR.$id.'.json');
    }

    /** @param array<string, mixed> $journal */
    private function hash(array $journal): string
    {
        return hash('sha256', json_encode($journal, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $plan @param array<string, mixed> $projection @return array<string, mixed> */
    private function projection(array $plan, array $projection): array
    {
        $allowed = collect($projection)->only([
            'installation_public_id', 'source_backup_public_id', 'source_vault_replica_public_id',
            'safety_backup_public_id', 'safety_vault_replica_public_id', 'requested_by_user_id',
            'reason', 'started_at', 'integration_delivery_hold',
        ])->all();

        return [
            'application_code' => (string) $plan['target']['application_code'],
            'environment' => (string) $plan['target']['environment'],
            'installation_public_id' => $allowed['installation_public_id'] ?? null,
            'source_type' => (string) $plan['source']['type'],
            'source_backup_public_id' => $allowed['source_backup_public_id'] ?? $plan['source']['artifact_id'] ?? null,
            'source_vault_replica_public_id' => $allowed['source_vault_replica_public_id'] ?? $plan['source']['vault_replica_id'] ?? null,
            'safety_backup_public_id' => $allowed['safety_backup_public_id'] ?? null,
            'safety_vault_replica_public_id' => $allowed['safety_vault_replica_public_id'] ?? null,
            'requested_by_user_id' => $allowed['requested_by_user_id'] ?? null,
            'reason' => mb_substr((string) ($allowed['reason'] ?? 'Restore gobernado'), 0, 2000),
            'plan_sha256' => (string) $plan['plan_sha256'],
            'source_sha256' => (string) $plan['source']['sha256'],
            'started_at' => $allowed['started_at'] ?? now()->utc()->toIso8601String(),
            'integration_delivery_hold' => (bool) ($allowed['integration_delivery_hold'] ?? true),
        ];
    }
}
