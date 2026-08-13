<?php

namespace Waadby\OperationsAgent\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Waadby\OperationsAgent\Contracts\OperationsReporter;
use Waadby\OperationsAgent\Remote\EnrollmentStore;

final class FilesystemOperationsReporter implements OperationsReporter
{
    public function __construct(private readonly Filesystem $files, private readonly EnrollmentStore $enrollment) {}

    public function installation(): array
    {
        $identity = $this->enrollment->get() ?? [];

        $localId = $identity['installation_id'] ?? null;
        if (! is_string($localId) || $localId === '') {
            $state = $this->read();
            $localId = $state['installation_public_id'] ?? null;
            if (! is_string($localId) || $localId === '') {
                $localId = (string) Str::uuid();
                $this->mutate(function (array &$mutable) use ($localId): bool {
                    $mutable['installation_public_id'] = $localId;

                    return true;
                });
            }
        }

        return [
            'public_id' => $localId,
            'application_code' => (string) config('waadby_operations.application.code'),
            'name' => (string) config('waadby_operations.application.name'),
            'environment' => $identity['environment'] ?? app()->environment(),
            'driver' => 'agent',
        ];
    }

    public function beginOperation(string $type, ?string $idempotencyKey = null, ?int $actorId = null): array
    {
        return $this->mutate(function (array &$state) use ($type, $idempotencyKey): array {
            if ($idempotencyKey !== null) {
                foreach ($state['operations'] as &$existing) {
                    if (($existing['idempotency_key'] ?? null) === $idempotencyKey) {
                        if (($existing['status'] ?? null) === 'queued') {
                            $existing['status'] = 'running';
                            $existing['started_at'] = $existing['started_at'] ?? now()->toIso8601String();
                        }

                        return $existing;
                    }
                }
            }
            $operation = [
                'public_id' => (string) Str::uuid(), 'operation_type' => $type, 'status' => 'running',
                'idempotency_key' => $idempotencyKey, 'started_at' => now()->toIso8601String(),
                'summary' => [], 'error_code' => null, 'error_message_safe' => null,
            ];
            $state['operations'][$operation['public_id']] = $operation;

            return $operation;
        });
    }

    public function queueOperation(string $type, string $idempotencyKey): array
    {
        return $this->mutate(function (array &$state) use ($type, $idempotencyKey): array {
            foreach ($state['operations'] as $existing) {
                if (($existing['idempotency_key'] ?? null) === $idempotencyKey) {
                    return $existing;
                }
            }
            $operation = [
                'public_id' => (string) Str::uuid(), 'operation_type' => $type, 'status' => 'queued',
                'idempotency_key' => $idempotencyKey, 'started_at' => null, 'finished_at' => null,
                'summary' => [], 'error_code' => null, 'error_message_safe' => null,
            ];
            $state['operations'][$operation['public_id']] = $operation;

            return $operation;
        });
    }

    public function findOperation(string $publicId): ?array
    {
        return $this->read()['operations'][$publicId] ?? null;
    }

    public function finishOperation(string $publicId, string $status, array $summary = [], ?string $errorCode = null, ?string $errorMessageSafe = null): void
    {
        $this->mutate(function (array &$state) use ($publicId, $status, $summary, $errorCode, $errorMessageSafe): bool {
            if (! isset($state['operations'][$publicId])) {
                throw new RuntimeException('La operación local no existe.');
            }
            $state['operations'][$publicId] = [...$state['operations'][$publicId],
                'status' => $status, 'summary' => $summary, 'error_code' => $errorCode,
                'error_message_safe' => $errorMessageSafe, 'finished_at' => now()->toIso8601String(),
            ];

            return true;
        });
    }

    public function createArtifact(array $attributes): array
    {
        return $this->mutate(function (array &$state) use ($attributes): array {
            $state['artifacts'][$attributes['public_id']] = $attributes;

            return $attributes;
        });
    }

    public function updateArtifact(string $publicId, array $attributes): void
    {
        $this->mutate(function (array &$state) use ($publicId, $attributes): bool {
            $state['artifacts'][$publicId] = [...($state['artifacts'][$publicId] ?? []), ...$attributes];

            return true;
        });
    }

    public function findArtifact(string $reference): ?array
    {
        $state = $this->read();
        if (isset($state['artifacts'][$reference])) {
            return $state['artifacts'][$reference];
        }
        foreach ($state['artifacts'] as $artifact) {
            if (($artifact['storage_path'] ?? null) === $reference) {
                return $artifact;
            }
        }

        return null;
    }

    public function audit(string $event, array $context = []): void
    {
        $this->mutate(function (array &$state) use ($event, $context): bool {
            $state['audit'][] = ['event' => $event, 'context' => $context, 'at' => now()->toIso8601String()];
            $state['audit'] = array_slice($state['audit'], -500);

            return true;
        });
    }

    /** @return array{installation_public_id?: string, operations: array<string, array<string, mixed>>, artifacts: array<string, array<string, mixed>>, audit: list<array<string, mixed>>} */
    private function read(): array
    {
        $path = $this->path();
        if (! $this->files->exists($path)) {
            return ['operations' => [], 'artifacts' => [], 'audit' => []];
        }
        $decoded = json_decode($this->files->get($path), true);

        return is_array($decoded) ? array_replace(['operations' => [], 'artifacts' => [], 'audit' => []], $decoded) : ['operations' => [], 'artifacts' => [], 'audit' => []];
    }

    private function mutate(callable $callback): mixed
    {
        $this->files->ensureDirectoryExists(dirname($this->path()), 0700, true);
        $lock = fopen($this->path().'.lock', 'c+');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('No se pudo bloquear el estado del agente.');
        }
        try {
            $state = $this->read();
            $result = $callback($state);
            $temporary = $this->path().'.tmp';
            $this->files->put($temporary, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true);
            @chmod($temporary, 0600);
            if (! @rename($temporary, $this->path())) {
                throw new RuntimeException('No se pudo persistir atómicamente el estado del agente.');
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function path(): string
    {
        return rtrim((string) config('waadby_operations.remote_agent.state_path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'operations.json';
    }
}
