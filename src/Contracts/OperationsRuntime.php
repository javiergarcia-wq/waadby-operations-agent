<?php

namespace Waadby\OperationsAgent\Contracts;

interface OperationsRuntime
{
    /** @return array<string, mixed> */
    public function registerSelf(): array;

    /** @return array<string, mixed> */
    public function inventory(bool $persist = true, ?string $idempotencyKey = null): array;

    /** @return array<string, mixed> */
    public function backup(string $type = 'operational', ?string $idempotencyKey = null, ?int $actorId = null): array;

    /** @return array<string, mixed> */
    public function verify(string $reference, ?string $idempotencyKey = null, ?int $actorId = null, bool $allowPortable = false): array;

    /** @return array<string, mixed> */
    public function restorePreflight(string $reference, ?string $idempotencyKey = null, ?int $actorId = null, bool $allowPortable = false): array;

    /** @return array<string, mixed> */
    public function updatePreflight(string $manifest, ?string $idempotencyKey = null, ?int $actorId = null): array;

    /** @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function updatePreflightDocument(array $manifest, ?string $idempotencyKey = null, ?int $actorId = null): array;
}
