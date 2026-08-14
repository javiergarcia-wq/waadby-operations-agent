<?php

namespace Waadby\OperationsAgent\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Waadby\OperationsAgent\Contracts\OperationsRuntime;
use Waadby\OperationsAgent\Updates\UpdateExecutor;
use Waadby\OperationsAgent\Updates\UpdateSessionStore;

final class ExecuteUpdateSession implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly string $sessionId) {}

    public function handle(UpdateSessionStore $sessions, UpdateExecutor $executor, OperationsRuntime $runtime): void
    {
        $state = $sessions->get($this->sessionId, true);
        if (($state['backup_verified'] ?? false) !== true) {
            $sessions->update($this->sessionId, ['status' => 'failed', 'result' => ['failure_code' => 'pre_update_backup_unverified']]);

            return;
        }
        $result = $executor->execute($state['manifest'], $state['signature'], $sessions->packagePath($this->sessionId), $this->sessionId, [
            'installation_public_id' => $state['installation_id'],
            'release_public_id' => $this->sessionId,
            'environment' => (string) config('waadby_operations.application.environment'),
            'backup_verified' => true,
            'vault_verified' => (bool) ($state['vault_verified'] ?? false),
            'on_phase' => fn (string $phase) => $sessions->update($this->sessionId, ['status' => $phase]),
        ]);
        $sessions->update($this->sessionId, ['status' => $result['status'], 'result' => collect($result)->except(['snapshot_path'])->all()]);
    }
}
