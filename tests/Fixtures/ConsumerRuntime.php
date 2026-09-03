<?php

namespace Tests\Fixtures;

use Waadby\OperationsAgent\Contracts\OperationsRuntime;

final class ConsumerRuntime implements OperationsRuntime
{
    /** @var list<array{method: string, arguments: array<int|string, mixed>}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function registerSelf(): array
    {
        return $this->record(__FUNCTION__, [], ['registered' => true]);
    }

    public function inventory(bool $persist = true, ?string $idempotencyKey = null): array
    {
        return $this->record(__FUNCTION__, compact('persist', 'idempotencyKey'), ['application_code' => 'waadby-billing']);
    }

    public function backup(string $type = 'operational', ?string $idempotencyKey = null, ?int $actorId = null): array
    {
        return $this->record(__FUNCTION__, compact('type', 'idempotencyKey', 'actorId'), ['status' => 'succeeded']);
    }

    public function verify(string $reference, ?string $idempotencyKey = null, ?int $actorId = null, bool $allowPortable = false): array
    {
        return $this->record(__FUNCTION__, compact('reference', 'idempotencyKey', 'actorId', 'allowPortable'), ['status' => 'succeeded']);
    }

    public function restorePreflight(string $reference, ?string $idempotencyKey = null, ?int $actorId = null, bool $allowPortable = false): array
    {
        return $this->record(__FUNCTION__, compact('reference', 'idempotencyKey', 'actorId', 'allowPortable'), ['status' => 'succeeded']);
    }

    public function updatePreflight(string $manifest, ?string $idempotencyKey = null, ?int $actorId = null): array
    {
        return $this->record(__FUNCTION__, compact('manifest', 'idempotencyKey', 'actorId'), ['status' => 'succeeded']);
    }

    public function updatePreflightDocument(array $manifest, ?string $idempotencyKey = null, ?int $actorId = null): array
    {
        return $this->record(__FUNCTION__, compact('manifest', 'idempotencyKey', 'actorId'), ['status' => 'succeeded']);
    }

    /** @param array<int|string, mixed> $arguments @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function record(string $method, array $arguments, array $result): array
    {
        self::$calls[] = compact('method', 'arguments');

        return $result;
    }
}
