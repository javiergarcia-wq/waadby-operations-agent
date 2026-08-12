<?php

namespace Waadby\OperationsAgent\Services;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

class InventoryService
{
    public function __construct(
        private readonly Application $application,
        private readonly DatabaseManager $database,
        private readonly FilesystemManager $filesystems,
        private readonly DatabaseRuntimeInfo $databaseInfo,
    ) {}

    /** @return array<string, mixed> */
    public function collect(): array
    {
        $connection = $this->database->connection();
        $migrationCount = 0;
        $lastMigration = null;
        $databaseVersion = null;

        try {
            if (Schema::connection($connection->getName())->hasTable('migrations')) {
                $migrationCount = (int) $connection->table('migrations')->count();
                $lastMigration = $connection->table('migrations')->orderByDesc('batch')->orderByDesc('id')->value('migration');
            }
            $databaseVersion = $this->databaseInfo->inspect()['version'];
        } catch (\Throwable) {
            // Inventory is best-effort and never exposes connection diagnostics or credentials.
        }

        $disk = (string) config('waadby_operations.backup.disk', 'local');
        $persistentPaths = array_values(array_filter((array) config('waadby_operations.backup.persistent_paths', []), 'is_string'));

        return [
            'application_code' => (string) config('waadby_operations.application.code'),
            'application_name' => (string) config('waadby_operations.application.name'),
            'environment' => $this->application->environment(),
            'application_version' => (string) config('waadby_operations.application.version'),
            'git_commit' => $this->gitCommit(),
            'php_version' => PHP_VERSION,
            'laravel_version' => $this->application->version(),
            'database_driver' => (string) $connection->getDriverName(),
            'database_version' => $databaseVersion,
            'migration_count' => $migrationCount,
            'last_migration' => $lastMigration,
            'storage_capabilities' => [
                'backup_disk' => $disk,
                'private' => $this->diskIsPrivate($disk),
                'persistent_path_count' => count($persistentPaths),
            ],
            'backup_capabilities' => [
                'operational' => true,
                'disaster' => app(SensitiveConfigurationCipher::class)->hasValidKey(config('waadby_operations.backup.key')),
                'verify' => class_exists(\ZipArchive::class),
            ],
            'update_capabilities' => [
                'preflight' => true,
                'apply' => false,
                'rollback' => false,
            ],
            'timestamp' => now()->utc()->toIso8601String(),
        ];
    }

    private function gitCommit(): ?string
    {
        try {
            $process = new Process(['git', 'rev-parse', 'HEAD'], base_path());
            $process->setTimeout(3);
            $process->run();

            return $process->isSuccessful() && preg_match('/^[a-f0-9]{40}$/', trim($process->getOutput()))
                ? trim($process->getOutput())
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function diskIsPrivate(string $disk): bool
    {
        $root = config("filesystems.disks.{$disk}.root");
        if (! is_string($root)) {
            return true;
        }

        return ! str_starts_with(str_replace('\\', '/', realpath($root) ?: $root), str_replace('\\', '/', public_path()));
    }
}
