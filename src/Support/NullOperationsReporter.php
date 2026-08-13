<?php

namespace Waadby\OperationsAgent\Support;

use Illuminate\Support\Str;
use Waadby\OperationsAgent\Contracts\OperationsReporter;

class NullOperationsReporter implements OperationsReporter
{
    /** @var array<string, array<string, mixed>> */
    private array $artifacts = [];

    /** @var array<string, array<string, mixed>> */
    private array $operations = [];

    public function installation(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'application_code' => (string) config('waadby_operations.application.code'),
            'name' => (string) config('waadby_operations.application.name'),
            'environment' => (string) config('waadby_operations.application.environment'),
            'driver' => 'self',
        ];
    }

    public function beginOperation(string $type, ?string $idempotencyKey = null, ?int $actorId = null): array
    {
        if ($idempotencyKey !== null) {
            foreach ($this->operations as $operation) {
                if (($operation['idempotency_key'] ?? null) === $idempotencyKey) {
                    return $operation;
                }
            }
        }
        $operation = ['public_id' => (string) Str::uuid(), 'operation_type' => $type, 'status' => 'running', 'idempotency_key' => $idempotencyKey];
        $this->operations[$operation['public_id']] = $operation;

        return $operation;
    }

    public function queueOperation(string $type, string $idempotencyKey): array
    {
        $operation = $this->beginOperation($type, $idempotencyKey);
        $operation['status'] = 'queued';
        $this->operations[$operation['public_id']] = $operation;

        return $operation;
    }

    public function findOperation(string $publicId): ?array
    {
        return $this->operations[$publicId] ?? null;
    }

    public function finishOperation(string $publicId, string $status, array $summary = [], ?string $errorCode = null, ?string $errorMessageSafe = null): void
    {
        if (isset($this->operations[$publicId])) {
            $this->operations[$publicId] = [...$this->operations[$publicId], 'status' => $status, 'summary' => $summary, 'error_code' => $errorCode, 'error_message_safe' => $errorMessageSafe];
        }
    }

    public function createArtifact(array $attributes): array
    {
        $this->artifacts[$attributes['public_id']] = $attributes;

        return $attributes;
    }

    public function updateArtifact(string $publicId, array $attributes): void
    {
        $this->artifacts[$publicId] = [...($this->artifacts[$publicId] ?? []), ...$attributes];
    }

    public function findArtifact(string $reference): ?array
    {
        return $this->artifacts[$reference] ?? null;
    }

    public function audit(string $event, array $context = []): void {}
}
