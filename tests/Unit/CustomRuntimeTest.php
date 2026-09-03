<?php

namespace Tests\Unit;

use Tests\TestCase;
use Waadby\OperationsAgent\Contracts\OperationsRuntime;

final class CustomRuntimeTest extends TestCase
{
    public function test_consumer_can_select_its_runtime_explicitly(): void
    {
        config(['waadby_operations.runtime' => ConsumerRuntime::class]);

        $this->assertInstanceOf(ConsumerRuntime::class, app(OperationsRuntime::class));
    }

    public function test_invalid_runtime_fails_closed(): void
    {
        config(['waadby_operations.runtime' => \stdClass::class]);

        $this->expectException(\RuntimeException::class);
        app(OperationsRuntime::class);
    }
}

final class ConsumerRuntime implements OperationsRuntime
{
    public function registerSelf(): array
    {
        return [];
    }

    public function inventory(bool $persist = true, ?string $idempotencyKey = null): array
    {
        return [];
    }

    public function backup(string $type = 'operational', ?string $idempotencyKey = null, ?int $actorId = null): array
    {
        return [];
    }

    public function verify(string $reference, ?string $idempotencyKey = null, ?int $actorId = null, bool $allowPortable = false): array
    {
        return [];
    }

    public function restorePreflight(string $reference, ?string $idempotencyKey = null, ?int $actorId = null, bool $allowPortable = false): array
    {
        return [];
    }

    public function updatePreflight(string $manifest, ?string $idempotencyKey = null, ?int $actorId = null): array
    {
        return [];
    }

    public function updatePreflightDocument(array $manifest, ?string $idempotencyKey = null, ?int $actorId = null): array
    {
        return [];
    }
}
