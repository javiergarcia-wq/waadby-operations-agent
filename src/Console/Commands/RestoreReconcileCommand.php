<?php

namespace Waadby\OperationsAgent\Console\Commands;

use Illuminate\Console\Command;
use Waadby\OperationsAgent\Contracts\OperationsReporter;
use Waadby\OperationsAgent\Restores\RestoreJournalStore;

final class RestoreReconcileCommand extends Command
{
    protected $signature = 'waadby:operations:restore:reconcile {--restore-id=} {--json}';

    protected $description = 'Proyecta estado/auditoria post-restore desde el journal privado';

    public function handle(RestoreJournalStore $journals, OperationsReporter $reporter): int
    {
        $selected = (string) $this->option('restore-id');
        $results = [];
        foreach ($journals->all() as $journal) {
            if ($selected !== '' && ($journal['restore_id'] ?? null) !== $selected) {
                continue;
            }
            $status = (string) ($journal['status'] ?? 'recovery_required');
            $event = in_array($status, ['succeeded', 'rolled_back'], true) ? 'operations.restore.completed' : 'operations.restore.recovery_required';
            $reporter->audit($event, ['restore_id' => $journal['restore_id'] ?? null, 'result' => $status, 'journal_sequence' => $journal['sequence'] ?? null]);
            $results[] = ['restore_id' => $journal['restore_id'] ?? null, 'status' => $status, 'event' => $event];
        }
        $this->line(json_encode(['reconciled' => $results], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
