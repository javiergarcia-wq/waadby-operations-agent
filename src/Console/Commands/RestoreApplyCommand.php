<?php

namespace Waadby\OperationsAgent\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Waadby\OperationsAgent\Restores\RestoreExecutor;

final class RestoreApplyCommand extends Command
{
    protected $signature = 'waadby:operations:restore:apply {plan} {source} {--safety-backup=} {--plan-sha=} {--confirm=} {--disaster-mode} {--reason=} {--acknowledge-no-safety-backup} {--json}';

    protected $description = 'Aplica un restore gobernado a partir de un plan autorizado';

    public function handle(RestoreExecutor $executor): int
    {
        try {
            $plan = json_decode((string) file_get_contents((string) $this->argument('plan')), true, 128, JSON_THROW_ON_ERROR);
            $expected = strtolower((string) $this->option('plan-sha'));
            if (! is_array($plan) || $expected === '' || ! hash_equals((string) ($plan['plan_sha256'] ?? ''), $expected)) {
                throw new RuntimeException('Debe proporcionar el plan-sha exacto del preflight.');
            }
            $confirmation = 'RESTORE '.$plan['target']['application_code'].' '.$plan['source']['backup_id'];
            if (! hash_equals($confirmation, (string) $this->option('confirm'))) {
                throw new RuntimeException('La confirmacion fuerte restore no coincide.');
            }
            $disaster = (bool) $this->option('disaster-mode');
            if ($disaster && (string) $this->option('safety-backup') !== '') {
                throw new RuntimeException('Disaster mode solo aplica cuando no existe safety backup utilizable.');
            }
            $result = $executor->execute($plan, (string) $this->argument('source'), (string) $this->option('safety-backup'), [
                'disaster_mode' => $disaster, 'reason' => $this->option('reason'),
                'acknowledge_no_safety_backup' => (bool) $this->option('acknowledge-no-safety-backup'),
                'safety_vault_verified' => ! app()->environment('production'),
            ]);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['status'] === 'succeeded' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
