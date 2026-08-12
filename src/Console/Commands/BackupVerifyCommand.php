<?php

namespace Waadby\OperationsAgent\Console\Commands;

use Illuminate\Console\Command;
use Waadby\OperationsAgent\Contracts\OperationsRuntime;

class BackupVerifyCommand extends Command
{
    protected $signature = 'waadby:operations:backup:verify {backup} {--idempotency-key=} {--json}';

    protected $description = 'Verifica integridad, manifest y checksums de un backup';

    public function handle(OperationsRuntime $runtime): int
    {
        try {
            $result = $runtime->verify((string) $this->argument('backup'), $this->option('idempotency-key'), allowPortable: true);
            $this->option('json')
                ? $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                : $this->info("Backup {$result['backup_id']} verificado correctamente.");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
