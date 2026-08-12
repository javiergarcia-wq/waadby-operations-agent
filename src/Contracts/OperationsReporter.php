<?php

namespace Waadby\OperationsAgent\Contracts;

interface OperationsReporter
{
    /** @return array<string, mixed> */
    public function installation(): array;

    /** @return array<string, mixed> */
    public function beginOperation(string $type, ?string $idempotencyKey = null, ?int $actorId = null): array;

    /** @param array<string, mixed> $summary */
    public function finishOperation(string $publicId, string $status, array $summary = [], ?string $errorCode = null, ?string $errorMessageSafe = null): void;

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createArtifact(array $attributes): array;

    /** @param array<string, mixed> $attributes */
    public function updateArtifact(string $publicId, array $attributes): void;

    /** @return array<string, mixed>|null */
    public function findArtifact(string $reference): ?array;

    /** @param array<string, mixed> $context */
    public function audit(string $event, array $context = []): void;
}
