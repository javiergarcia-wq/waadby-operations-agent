<?php

namespace Waadby\OperationsAgent\Support;

use Illuminate\Support\Str;
use Waadby\OperationsAgent\Contracts\OperationsReporter;

class NullOperationsReporter implements OperationsReporter
{
    /** @var array<string, array<string, mixed>> */
    private array $artifacts = [];

    public function installation(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'application_code' => (string) config('waadby_operations.application.code'),
            'name' => (string) config('waadby_operations.application.name'),
            'environment' => app()->environment(),
            'driver' => 'self',
        ];
    }

    public function beginOperation(string $type, ?string $idempotencyKey = null, ?int $actorId = null): array
    {
        return ['public_id' => (string) Str::uuid(), 'operation_type' => $type, 'status' => 'running'];
    }

    public function finishOperation(string $publicId, string $status, array $summary = [], ?string $errorCode = null, ?string $errorMessageSafe = null): void {}

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
        return $this->artifacts[$reference] ?? (is_file($reference) ? [
            'public_id' => null,
            'status' => null,
            'absolute_path' => $reference,
            'storage_path' => $reference,
        ] : null);
    }

    public function audit(string $event, array $context = []): void {}
}
