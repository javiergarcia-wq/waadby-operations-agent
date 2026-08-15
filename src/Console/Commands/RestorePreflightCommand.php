<?php

namespace Waadby\OperationsAgent\Console\Commands;

use Illuminate\Console\Command;
use Waadby\OperationsAgent\Services\RestorePreflightService;

class RestorePreflightCommand extends Command
{
    protected $signature = 'waadby:operations:restore:preflight {backup} {--source-type=portable_zip} {--artifact-id=} {--remote-artifact-id=} {--vault-replica-id=} {--plan-output=} {--json}';

    protected $description = 'Analiza compatibilidad de restore sin modificar datos';

    public function handle(RestorePreflightService $preflight): int
    {
        try {
            $result = $preflight->plan((string) $this->argument('backup'), [
                'type' => (string) $this->option('source-type'),
                'artifact_id' => $this->option('artifact-id'),
                'remote_artifact_id' => $this->option('remote-artifact-id'),
                'vault_replica_id' => $this->option('vault-replica-id'),
            ], allowPortable: true);
            if (is_string($this->option('plan-output')) && $this->option('plan-output') !== '') {
                $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
                if (file_put_contents((string) $this->option('plan-output'), $json, LOCK_EX) !== strlen($json)) {
                    throw new \RuntimeException('No se pudo escribir el plan restore.');
                }
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
