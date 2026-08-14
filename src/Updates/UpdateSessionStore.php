<?php

namespace Waadby\OperationsAgent\Updates;

use Illuminate\Support\Str;
use RuntimeException;
use Waadby\OperationsAgent\Support\OperationsPrivateStoragePathPolicy;

final class UpdateSessionStore
{
    public function __construct(private readonly OperationsPrivateStoragePathPolicy $privateStorage) {}

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    public function create(array $metadata): array
    {
        $sessionId = (string) Str::uuid();
        $directory = $this->directory($sessionId, true);
        $state = [
            'session_id' => $sessionId,
            'installation_id' => $metadata['installation_id'],
            'release_metadata' => ['application_code' => $metadata['manifest']['application_code'], 'version' => $metadata['manifest']['version'], 'source_commit' => $metadata['manifest']['source_commit']],
            'manifest' => $metadata['manifest'], 'signature' => $metadata['signature'],
            'backup_id' => $metadata['backup_id'], 'backup_verified' => true, 'vault_verified' => (bool) $metadata['vault_verified'],
            'expected_sha' => strtolower($metadata['package_sha256']), 'expected_size' => (int) $metadata['package_size'],
            'received_bytes' => 0, 'next_chunk_index' => 0, 'status' => 'prepared',
            'created_at' => now()->utc()->toIso8601String(), 'updated_at' => now()->utc()->toIso8601String(),
            'result' => null,
        ];
        $this->write($sessionId, $state);

        return $this->safe($state);
    }

    /** @return array<string, mixed> */
    public function append(string $sessionId, int $index, int $offset, string $body): array
    {
        if (strlen($body) === 0 || strlen($body) > (int) config('waadby_operations.updates.chunk_bytes', 262144)) {
            throw new RuntimeException('El chunk supera el limite o esta vacio.');
        }

        return $this->mutate($sessionId, function (array &$state) use ($sessionId, $index, $offset, $body): void {
            if (! in_array($state['status'], ['prepared', 'transferring'], true) || $index !== $state['next_chunk_index'] || $offset !== $state['received_bytes']) {
                throw new RuntimeException('El chunk no coincide con el offset/indice esperado.');
            }
            if ($state['expected_size'] < $state['received_bytes'] + strlen($body)) {
                throw new RuntimeException('El chunk excede el package declarado.');
            }
            $handle = fopen($this->packagePath($sessionId), 'ab');
            if ($handle === false) {
                throw new RuntimeException('No se pudo abrir el staging de chunks.');
            }
            try {
                if (! flock($handle, LOCK_EX) || fwrite($handle, $body) !== strlen($body) || ! fflush($handle)) {
                    throw new RuntimeException('No se pudo persistir el chunk completo.');
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
            $this->privateStorage->protectFile($this->packagePath($sessionId));
            $state['received_bytes'] += strlen($body);
            $state['next_chunk_index']++;
            $state['status'] = 'transferring';
        });
    }

    /** @return array<string, mixed> */
    public function get(string $sessionId, bool $includePrivate = false): array
    {
        $this->assertId($sessionId);
        $path = $this->statePath($sessionId);
        if (! is_file($path)) {
            throw new RuntimeException('La sesion de update no existe.');
        }
        $state = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($state)) {
            throw new RuntimeException('La sesion de update esta corrupta.');
        }

        return $includePrivate ? $state : $this->safe($state);
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    public function update(string $sessionId, array $attributes): array
    {
        return $this->mutate($sessionId, function (array &$state) use ($attributes): void {
            foreach (['status', 'result'] as $key) {
                if (array_key_exists($key, $attributes)) {
                    $state[$key] = $attributes[$key];
                }
            }
        });
    }

    public function packagePath(string $sessionId): string
    {
        $directory = $this->directory($sessionId);

        return $this->privateStorage->assertFileWithinRoot($directory, $directory.DIRECTORY_SEPARATOR.'package.zip');
    }

    /** @param callable(array<string,mixed>&):void $callback @return array<string, mixed> */
    private function mutate(string $sessionId, callable $callback): array
    {
        $this->assertId($sessionId);
        $directory = $this->directory($sessionId);
        $lockPath = $this->privateStorage->assertFileWithinRoot($directory, $directory.DIRECTORY_SEPARATOR.'session.lock');
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('No se pudo bloquear la sesion de update.');
        }
        $this->privateStorage->protectFile($lockPath);
        try {
            $state = $this->get($sessionId, true);
            $callback($state);
            $state['updated_at'] = now()->utc()->toIso8601String();
            $this->write($sessionId, $state);

            return $this->safe($state);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $state */
    private function write(string $sessionId, array $state): void
    {
        $path = $this->statePath($sessionId);
        $temporary = $path.'.tmp';
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
            throw new RuntimeException('No se pudo escribir la sesion de update.');
        }
        $this->privateStorage->protectFile($temporary);
        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo publicar la sesion de update.');
        }
        $this->privateStorage->protectFile($path);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function safe(array $state): array
    {
        return collect($state)->only(['session_id', 'installation_id', 'release_metadata', 'expected_sha', 'expected_size', 'received_bytes', 'next_chunk_index', 'status', 'created_at', 'updated_at', 'result'])->all();
    }

    private function directory(string $sessionId, bool $create = false): string
    {
        $this->assertId($sessionId);
        $root = (string) config('waadby_operations.remote_agent.state_path');

        $updates = $this->privateStorage->prepareChildDirectory($root, 'updates');

        return $create
            ? $this->privateStorage->prepareChildDirectory($updates, $sessionId)
            : $this->privateStorage->existingChildDirectory($updates, $sessionId);
    }

    private function statePath(string $sessionId): string
    {
        $directory = $this->directory($sessionId);

        return $this->privateStorage->assertFileWithinRoot($directory, $directory.DIRECTORY_SEPARATOR.'session.json');
    }

    private function assertId(string $sessionId): void
    {
        if (! Str::isUuid($sessionId)) {
            throw new RuntimeException('session_id no es valido.');
        }
    }
}
