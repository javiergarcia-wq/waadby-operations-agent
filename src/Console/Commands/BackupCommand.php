<?php

namespace Waadby\OperationsAgent\Console\Commands;

use Illuminate\Console\Command;
use Waadby\OperationsAgent\Contracts\OperationsRuntime;

class BackupCommand extends Command
{
    protected $signature = 'waadby:operations:backup {--type=operational : operational|disaster} {--idempotency-key=} {--json}';

    protected $description = 'Crea y verifica un backup privado de la instalacion local';

    public function handle(OperationsRuntime $runtime): int
    {
        try {
            $result = $runtime->backup((string) $this->option('type'), $this->option('idempotency-key'));
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->info("Backup {$result['public_id']} creado con estado {$result['status']}.");
                $this->line("Tipo: {$result['backup_type']}");
                $this->line("Tamano: {$result['size_bytes']} bytes");
                $this->line('SHA-256: '.$result['sha256']);
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
