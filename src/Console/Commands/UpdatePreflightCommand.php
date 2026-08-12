<?php

namespace Waadby\OperationsAgent\Console\Commands;

use Illuminate\Console\Command;
use Waadby\OperationsAgent\Contracts\OperationsRuntime;

class UpdatePreflightCommand extends Command
{
    protected $signature = 'waadby:operations:update:preflight {manifest} {--idempotency-key=} {--json}';

    protected $description = 'Valida un release manifest sin modificar codigo, datos ni configuracion';

    public function handle(OperationsRuntime $runtime): int
    {
        try {
            $result = $runtime->updatePreflight((string) $this->argument('manifest'), $this->option('idempotency-key'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $result['compatible'] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
