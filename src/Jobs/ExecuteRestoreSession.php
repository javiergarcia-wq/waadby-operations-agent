<?php

namespace Waadby\OperationsAgent\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Waadby\OperationsAgent\Contracts\OperationsReporter;
use Waadby\OperationsAgent\Restores\IntegrationDeliveryHoldFile;
use Waadby\OperationsAgent\Restores\RestoreExecutor;
use Waadby\OperationsAgent\Restores\RestoreSessionStore;

final class ExecuteRestoreSession implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public function __construct(public readonly string $sessionId) {}

    public function handle(RestoreSessionStore $sessions, OperationsReporter $reporter, RestoreExecutor $executor, IntegrationDeliveryHoldFile $hold): void
    {
        $state = $sessions->get($this->sessionId, true);
        if ($state['status'] !== 'queued') {
            return;
        }
        $sessions->update($this->sessionId, ['status' => 'ready_to_restore']);
        $source = ($state['source_mode'] ?? null) === 'upload'
            ? ['absolute_path' => $sessions->sourcePath($this->sessionId)]
            : $reporter->findArtifact($state['backup_reference']);
        $safetyArtifact = $reporter->findArtifact((string) $state['safety_backup_reference']);
        $result = $executor->execute($state['plan'], (string) ($source['absolute_path'] ?? $source['storage_path']), (string) ($safetyArtifact['absolute_path'] ?? $safetyArtifact['storage_path']), [
            'safety_vault_verified' => (bool) ($state['safety_vault_verified'] ?? false),
            'hold_integrations' => fn (string $restoreId) => $hold->enable($restoreId, (string) ($state['reason'] ?? 'Remote governed restore')),
        ]);
        $sessions->update($this->sessionId, ['status' => $result['status'], 'result' => $result]);
    }

    public function failed(\Throwable $exception): void
    {
        app(RestoreSessionStore::class)->update($this->sessionId, ['status' => 'recovery_required', 'result' => ['failure_message_safe' => mb_substr($exception->getMessage(), 0, 500)]]);
    }
}
