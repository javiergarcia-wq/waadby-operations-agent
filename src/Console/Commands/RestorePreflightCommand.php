<?php

namespace Waadby\OperationsAgent\Console\Commands;

use Illuminate\Console\Command;
use Waadby\OperationsAgent\Contracts\OperationsRuntime;

class RestorePreflightCommand extends Command
{
    protected $signature = 'waadby:operations:restore:preflight {backup} {--idempotency-key=} {--json}';

    protected $description = 'Analiza compatibilidad de restore sin modificar datos';

    public function handle(OperationsRuntime $runtime): int
    {
        try {
            $result = $runtime->restorePreflight((string) $this->argument('backup'), $this->option('idempotency-key'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $result['compatible'] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
