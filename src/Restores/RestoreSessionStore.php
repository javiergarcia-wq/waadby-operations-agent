<?php

namespace Waadby\OperationsAgent\Restores;

use Illuminate\Support\Str;
use RuntimeException;
use Waadby\OperationsAgent\Support\OperationsPrivateStoragePathPolicy;

final class RestoreSessionStore
{
    public function __construct(private readonly OperationsPrivateStoragePathPolicy $paths) {}

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    public function createLocal(string $installationId, string $backupReference, array $plan): array
    {
        $id = (string) $plan['plan_id'];
        $this->directory($id, true);
        $state = $this->baseState($id, $installationId, $backupReference, 'local', $plan) + [
            'expected_sha' => (string) $plan['source']['sha256'], 'expected_size' => (int) $plan['source']['size_bytes'],
            'received_bytes' => 0, 'next_chunk_index' => 0,
        ];
        $this->write($id, $state);

        return $this->safe($state);
    }

    /** @return array<string, mixed> */
    public function createUpload(string $installationId, string $backupReference, string $artifactReference, string $sha256, int $size): array
    {
        $id = (string) Str::uuid();
        $this->directory($id, true);
        $state = $this->baseState($id, $installationId, $backupReference, 'upload', null) + [
            'artifact_reference' => $artifactReference, 'expected_sha' => strtolower($sha256), 'expected_size' => $size,
            'received_bytes' => 0, 'next_chunk_index' => 0,
        ];
        $this->write($id, $state);

        return $this->safe($state);
    }

    /** @return array<string, mixed> */
    public function append(string $id, int $index, int $offset, string $body): array
    {
        $length = strlen($body);
        if ($length === 0 || $length > (int) config('waadby_operations.restores.chunk_bytes', 262144)) {
            throw new RuntimeException('El chunk restore supera el limite o esta vacio.');
        }

        return $this->mutate($id, function (array &$state) use ($id, $index, $offset, $body, $length): void {
            if (($state['source_mode'] ?? null) !== 'upload' || ! in_array($state['status'], ['prepared', 'transferring'], true)
                || $index !== $state['next_chunk_index'] || $offset !== $state['received_bytes']) {
                throw new RuntimeException('El chunk restore no coincide con el cursor esperado.');
            }
            if ($state['expected_size'] < $state['received_bytes'] + $length) {
                throw new RuntimeException('El chunk excede el backup restore declarado.');
            }
            $handle = fopen($this->sourcePath($id), 'ab');
            if ($handle === false) {
                throw new RuntimeException('No se pudo abrir el staging restore remoto.');
            }
            try {
                if (! flock($handle, LOCK_EX) || fwrite($handle, $body) !== $length || ! fflush($handle)) {
                    throw new RuntimeException('No se pudo persistir el chunk restore completo.');
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
            $this->paths->protectFile($this->sourcePath($id));
            $state['received_bytes'] += $length;
            $state['next_chunk_index']++;
            $state['status'] = 'transferring';
        });
    }

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    public function finalize(string $id, array $plan): array
    {
        return $this->mutate($id, function (array &$state) use ($plan): void {
            if (($state['source_mode'] ?? null) !== 'upload' || ! in_array($state['status'], ['prepared', 'transferring'], true)) {
                throw new RuntimeException('La sesion restore no admite finalize.');
            }
            if ($state['received_bytes'] !== $state['expected_size']) {
                throw new RuntimeException('El backup restore transferido esta incompleto.');
            }
            $state['plan'] = $plan;
            $state['status'] = 'staged';
        });
    }

    /** @return array<string, mixed> */
    public function get(string $id, bool $private = false): array
    {
        $path = $this->statePath($id);
        if (! is_file($path)) {
            throw new RuntimeException('La sesion restore no existe.');
        }
        $state = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        if (! is_array($state)) {
            throw new RuntimeException('La sesion restore esta corrupta.');
        }

        return $private ? $state : $this->safe($state);
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    public function update(string $id, array $values): array
    {
        return $this->mutate($id, function (array &$state) use ($values): void {
            foreach (['status', 'result', 'reason', 'actor_id', 'safety_backup_reference', 'safety_vault_verified'] as $key) {
                if (array_key_exists($key, $values)) {
                    $state[$key] = $values[$key];
                }
            }
        });
    }

    public function sourcePath(string $id): string
    {
        $directory = $this->directory($id);

        return $this->paths->assertFileWithinRoot($directory, $directory.DIRECTORY_SEPARATOR.'source.zip');
    }

    /** @param callable(array<string,mixed>&):void $callback @return array<string, mixed> */
    private function mutate(string $id, callable $callback): array
    {
        $directory = $this->directory($id);
        $lockPath = $this->paths->assertFileWithinRoot($directory, $directory.DIRECTORY_SEPARATOR.'session.lock');
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('No se pudo bloquear la sesion restore.');
        }
        $this->paths->protectFile($lockPath);
        try {
            $state = $this->get($id, true);
            $callback($state);
            $state['updated_at'] = now()->utc()->toIso8601String();
            $this->write($id, $state);

            return $this->safe($state);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, mixed> */
    private function baseState(string $id, string $installationId, string $backupReference, string $sourceMode, ?array $plan): array
    {
        return [
            'session_id' => $id, 'installation_id' => $installationId, 'backup_reference' => $backupReference,
            'source_mode' => $sourceMode, 'plan' => $plan, 'status' => 'prepared', 'result' => null,
            'created_at' => now()->utc()->toIso8601String(), 'updated_at' => now()->utc()->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $state */
    private function write(string $id, array $state): void
    {
        $path = $this->statePath($id);
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $temporary = $path.'.tmp';
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
            throw new RuntimeException('No se pudo escribir la sesion restore.');
        }
        $this->paths->protectFile($temporary);
        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo publicar la sesion restore.');
        }
        $this->paths->protectFile($path);
    }

    private function statePath(string $id): string
    {
        $directory = $this->directory($id);

        return $this->paths->assertFileWithinRoot($directory, $directory.DIRECTORY_SEPARATOR.'session.json');
    }

    private function directory(string $id, bool $create = false): string
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('session_id restore no es valido.');
        }
        $root = $this->paths->prepareChildDirectory((string) config('waadby_operations.remote_agent.state_path'), 'restores');

        return $create ? $this->paths->prepareChildDirectory($root, $id) : $this->paths->existingChildDirectory($root, $id);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function safe(array $state): array
    {
        return collect($state)->only(['session_id', 'installation_id', 'plan', 'expected_sha', 'expected_size', 'received_bytes', 'next_chunk_index', 'status', 'result', 'created_at', 'updated_at'])->all();
    }
}
