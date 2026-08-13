<?php

namespace Waadby\OperationsAgent\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Waadby\OperationsAgent\Contracts\OperationsReporter;
use Waadby\OperationsAgent\Contracts\OperationsRuntime;

final class ExecuteRemoteOperation implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** @param array<string, mixed> $payload */
    public function __construct(public readonly string $type, public readonly string $idempotencyKey, public readonly array $payload) {}

    public function handle(OperationsRuntime $runtime, OperationsReporter $reporter): void
    {
        match ($this->type) {
            'backup' => $runtime->backup((string) $this->payload['type'], $this->idempotencyKey),
            'backup_verify' => $runtime->verify((string) $this->payload['backup_id'], $this->idempotencyKey, allowPortable: false),
            'restore_preflight' => $runtime->restorePreflight((string) $this->payload['backup_id'], $this->idempotencyKey, allowPortable: false),
            'update_preflight' => $runtime->updatePreflightDocument($this->payload['manifest'], $this->idempotencyKey),
            default => throw new \RuntimeException('Operación remota no soportada.'),
        };
    }
}
