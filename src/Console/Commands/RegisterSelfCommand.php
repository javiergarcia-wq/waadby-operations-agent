<?php

namespace Waadby\OperationsAgent\Console\Commands;

use Illuminate\Console\Command;
use Waadby\OperationsAgent\Contracts\OperationsRuntime;

class RegisterSelfCommand extends Command
{
    protected $signature = 'waadby:operations:register-self {--json}';

    protected $description = 'Registra idempotentemente la instalacion local';

    public function handle(OperationsRuntime $runtime): int
    {
        try {
            $installation = $runtime->registerSelf();
            $this->option('json')
                ? $this->line(json_encode($installation, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                : $this->info("Instalacion {$installation['application_code']} ({$installation['environment']}) registrada: {$installation['public_id']}");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
