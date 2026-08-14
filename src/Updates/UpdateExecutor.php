<?php

namespace Waadby\OperationsAgent\Updates;

use Illuminate\Cache\CacheManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Factory;
use RuntimeException;
use Symfony\Component\Process\Process;
use Waadby\OperationsAgent\Services\UpdatePreflightService;

final class UpdateExecutor
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly DatabaseManager $database,
        private readonly Factory $http,
        private readonly ReleasePackageVerifier $packages,
        private readonly UpdatePreflightService $preflight,
        private readonly CodeSnapshotService $snapshots,
        private readonly CodeApplyService $code,
        private readonly InstalledReleaseStore $installedRelease,
    ) {}

    /** @param array<string, mixed> $manifest @param array<string, mixed> $signature @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function execute(array $manifest, array $signature, string $packagePath, string $sessionId, array $context = []): array
    {
        if (! (bool) config('waadby_operations.updates.apply_enabled', false)) {
            throw new RuntimeException('UPDATE APPLY esta desactivado por feature flag.');
        }
        $lockKey = (string) ($context['installation_public_id'] ?? config('waadby_operations.application.code'));
        $lock = $this->cache->lock('waadby-operations:update:'.hash('sha256', $lockKey), 3600);
        if (! $lock->get()) {
            throw new RuntimeException('Ya existe una actualizacion activa para esta instalacion.');
        }
        try {
            return $this->executeLocked($manifest, $signature, $packagePath, $sessionId, $context);
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $manifest @param array<string, mixed> $signature @param array<string, mixed> $context */
    private function executeLocked(array $manifest, array $signature, string $packagePath, string $sessionId, array $context): array
    {
        $phase = 'preflight';
        $maintenance = false;
        $migrationsStarted = false;
        $migrationsCompleted = false;
        $appliedMigrations = [];
        $migrationBatch = null;
        $snapshot = null;
        $root = (string) ($context['root'] ?? base_path());
        $staging = rtrim((string) config('waadby_operations.updates.staging_path', storage_path('app/private/waadby-operations/releases')), '/\\').DIRECTORY_SEPARATOR.$sessionId;
        $emit = function (string $name, array $data = []) use ($context, &$phase): void {
            $phase = $name;
            if (isset($context['on_phase']) && is_callable($context['on_phase'])) {
                ($context['on_phase'])($name, $data);
            }
        };

        try {
            $emit('preflight');
            $verified = $this->packages->verify($manifest, $signature, $packagePath);
            $preflight = $this->preflight->analyze($manifest, $sessionId.':preflight', $context['actor_id'] ?? null);
            if (! $preflight['compatible']) {
                throw new RuntimeException($preflight['blockers'][0] ?? 'El preflight de update fue bloqueado.');
            }
            $environment = (string) ($context['environment'] ?? config('waadby_operations.application.environment'));
            $productionVault = $environment === 'production' && (bool) config('waadby_operations.updates.require_vault_production', true);
            if (((bool) ($manifest['backup_required'] ?? false) || $productionVault) && ($context['backup_verified'] ?? false) !== true) {
                throw new RuntimeException('El backup PRE-UPDATE especifico no esta VERIFIED.');
            }
            if ($productionVault && ($context['vault_verified'] ?? false) !== true) {
                throw new RuntimeException('Produccion exige una VaultReplica VERIFIED del backup PRE-UPDATE especifico.');
            }

            $emit('staged');
            $this->packages->extract($packagePath, $staging, $verified['files']);
            $snapshot = $this->snapshots->create($root, $sessionId, $verified['files']);
            $emit('snapshot_verified', ['path' => $snapshot['path'], 'sha256' => $snapshot['sha256']]);

            if ((bool) ($manifest['maintenance_required'] ?? false)) {
                $emit('maintenance');
                $this->command([PHP_BINARY, 'artisan', 'down', '--retry=60', '--no-ansi', '--no-interaction'], $root, $context, 'maintenance_down');
                $maintenance = true;
            }

            $emit('applying_code');
            $this->code->apply($root, $staging, $verified['files']);
            $this->command([PHP_BINARY, 'artisan', 'optimize:clear', '--no-ansi', '--no-interaction'], $root, $context, 'cache_clear');
            $emit('code_applied');

            if ((bool) ($manifest['database']['migrations'] ?? false)) {
                $emit('migrating');
                $before = $this->migrationRows();
                $migrationsStarted = true;
                if (isset($context['migration_runner']) && is_callable($context['migration_runner']) && app()->environment('testing')) {
                    $migrationResult = ($context['migration_runner'])('up', null);
                    $migrationBatch = $migrationResult['batch'] ?? null;
                    $appliedMigrations = $migrationResult['applied'] ?? [];
                    $migrationsCompleted = true;
                } else {
                    try {
                        $this->command([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-ansi', '--no-interaction'], $root, $context, 'migrate');
                        $migrationsCompleted = true;
                    } finally {
                        $after = $this->migrationRows();
                        $appliedMigrations = array_values(array_diff(array_keys($after), array_keys($before)));
                        $batches = array_values(array_unique(array_map(fn (string $name): int => $after[$name], $appliedMigrations)));
                        $migrationBatch = count($batches) === 1 ? $batches[0] : null;
                    }
                }
                $emit('migrations_completed', ['migration_batch' => $migrationBatch, 'applied_migrations' => $appliedMigrations]);
            }

            $this->installedRelease->write([
                'application_code' => (string) $manifest['application_code'],
                'version' => (string) $manifest['version'],
                'source_commit' => strtolower((string) $manifest['source_commit']),
                'release_public_id' => (string) ($context['release_public_id'] ?? $sessionId),
                'applied_at' => now()->utc()->toIso8601String(),
            ]);

            $emit('healthchecking');
            $health = $this->healthchecks($manifest, $context, $root);
            if ($maintenance) {
                $this->command([PHP_BINARY, 'artisan', 'up', '--no-ansi', '--no-interaction'], $root, $context, 'maintenance_up');
                $maintenance = false;
            }
            $this->command([PHP_BINARY, 'artisan', 'queue:restart', '--no-ansi', '--no-interaction'], $root, $context, 'queue_restart', optional: true);
            $emit('succeeded');
            $this->removeTree($staging);

            return [
                'status' => 'succeeded', 'last_safe_phase' => 'succeeded', 'snapshot_path' => $snapshot['path'],
                'snapshot_sha256' => $snapshot['sha256'], 'migration_batch' => $migrationBatch,
                'applied_migrations' => $appliedMigrations, 'healthchecks' => $health,
            ];
        } catch (\Throwable $exception) {
            $safe = $this->safeMessage($exception);
            if ($snapshot === null) {
                $emit('failed', ['failure_code' => 'update_precondition_failed']);

                return ['status' => 'failed', 'failure_code' => 'update_precondition_failed', 'failure_message_safe' => $safe, 'last_safe_phase' => $phase];
            }
            $emit('rolling_back', ['failure_code' => 'update_apply_failed']);
            try {
                $rollbackPolicy = (string) ($manifest['database']['rollback_policy'] ?? 'forward_only');
                $compatible = (bool) ($manifest['deployment']['backward_compatible_with_previous'] ?? false);
                if ($migrationsStarted && ! $migrationsCompleted) {
                    throw new RuntimeException('El subproceso de migrations quedo en un estado ambiguo.');
                }
                if ($migrationsStarted && $appliedMigrations !== []) {
                    if ($rollbackPolicy === 'rollback_safe' && is_int($migrationBatch)) {
                        if (isset($context['migration_runner']) && is_callable($context['migration_runner']) && app()->environment('testing')) {
                            ($context['migration_runner'])('down', $migrationBatch);
                        } else {
                            $this->command([PHP_BINARY, 'artisan', 'migrate:rollback', '--batch='.$migrationBatch, '--force', '--no-ansi', '--no-interaction'], $root, $context, 'migrate_rollback');
                            $remaining = $this->migrationRows();
                            if (array_intersect($appliedMigrations, array_keys($remaining)) !== []) {
                                throw new RuntimeException('El batch de migrations no se revirtio por completo.');
                            }
                        }
                    } elseif (! $compatible) {
                        throw new RuntimeException('Esquema forward_only incompatible: se requiere recuperacion gobernada.');
                    }
                }
                $this->snapshots->restore($root, $snapshot['path'], $snapshot['sha256']);
                $this->command([PHP_BINARY, 'artisan', 'optimize:clear', '--no-ansi', '--no-interaction'], $root, $context, 'rollback_cache_clear');
                $this->rollbackSmoke($context, $root);
                if ($maintenance) {
                    $this->command([PHP_BINARY, 'artisan', 'up', '--no-ansi', '--no-interaction'], $root, $context, 'maintenance_up');
                    $maintenance = false;
                }
                $emit('rolled_back');

                return [
                    'status' => 'rolled_back', 'failure_code' => 'update_apply_failed', 'failure_message_safe' => $safe,
                    'last_safe_phase' => 'rolled_back', 'snapshot_path' => $snapshot['path'], 'snapshot_sha256' => $snapshot['sha256'],
                    'migration_batch' => $migrationBatch, 'applied_migrations' => $appliedMigrations,
                ];
            } catch (\Throwable $rollbackException) {
                if (! $maintenance) {
                    try {
                        $this->command([PHP_BINARY, 'artisan', 'down', '--retry=60', '--no-ansi', '--no-interaction'], $root, $context, 'recovery_maintenance_down');
                        $maintenance = true;
                    } catch (\Throwable) {
                        // The recovery result still records that maintenance could not be confirmed.
                    }
                }
                $emit('recovery_required', ['failure_code' => 'update_recovery_required']);

                return [
                    'status' => 'recovery_required', 'failure_code' => 'update_recovery_required',
                    'failure_message_safe' => $this->safeMessage($rollbackException), 'last_safe_phase' => $phase,
                    'snapshot_path' => $snapshot['path'], 'snapshot_sha256' => $snapshot['sha256'],
                    'migration_batch' => $migrationBatch, 'applied_migrations' => $appliedMigrations,
                    'maintenance_active' => $maintenance,
                ];
            }
        }
    }

    /** @param array<string, mixed> $manifest @param array<string, mixed> $context @return list<array<string, mixed>> */
    private function healthchecks(array $manifest, array $context, string $root): array
    {
        if (isset($context['healthcheck_runner']) && is_callable($context['healthcheck_runner']) && app()->environment('testing')) {
            $result = ($context['healthcheck_runner'])($manifest['healthchecks'] ?? []);
            if (! is_array($result)) {
                throw new RuntimeException('El healthcheck de prueba fallo.');
            }

            return $result;
        }
        $baseUrl = rtrim((string) ($context['base_url'] ?? config('app.url')), '/');
        $originHost = parse_url($baseUrl, PHP_URL_HOST);
        if (! in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true) || ! is_string($originHost)) {
            throw new RuntimeException('No existe un origen local valido para healthchecks.');
        }
        $results = [];
        foreach ($manifest['healthchecks'] ?? [] as $path) {
            if (! is_string($path) || ! preg_match('#^/(?!/)#', $path) || parse_url($path, PHP_URL_HOST) !== null) {
                throw new RuntimeException('El manifest intenta ejecutar un healthcheck externo.');
            }
            $started = hrtime(true);
            $response = $this->http->withOptions(['allow_redirects' => false, 'verify' => true])->timeout(10)->get($baseUrl.$path);
            $latency = (int) ((hrtime(true) - $started) / 1000000);
            if ($response->status() < 200 || $response->status() >= 300) {
                throw new RuntimeException("Healthcheck {$path} fallo con HTTP {$response->status()}.");
            }
            $results[] = ['path' => $path, 'status' => $response->status(), 'latency_ms' => $latency, 'result' => 'ok'];
        }
        $this->database->connection()->select('select 1');
        $this->command([PHP_BINARY, 'artisan', '--version', '--no-ansi'], $root, $context, 'bootstrap_smoke');
        $status = $this->command([PHP_BINARY, 'artisan', 'migrate:status', '--no-ansi', '--no-interaction'], $root, $context, 'migration_status');
        if (preg_match('/\bPending\b/i', $status)) {
            throw new RuntimeException('Quedan migrations pendientes despues del apply.');
        }
        if (! is_writable(dirname($this->installedRelease->path()))) {
            throw new RuntimeException('Operations state privado no es escribible.');
        }
        $state = $this->installedRelease->read();
        if (($state['version'] ?? null) !== ($manifest['version'] ?? null)) {
            throw new RuntimeException('El version smoke no coincide con el release objetivo.');
        }

        return $results;
    }

    /** @param array<string, mixed> $context */
    private function rollbackSmoke(array $context, string $root): void
    {
        if (isset($context['rollback_healthcheck_runner']) && is_callable($context['rollback_healthcheck_runner']) && app()->environment('testing')) {
            if (($context['rollback_healthcheck_runner'])() !== true) {
                throw new RuntimeException('El healthcheck de rollback fallo.');
            }

            return;
        }
        $this->database->connection()->select('select 1');
        $this->command([PHP_BINARY, 'artisan', '--version', '--no-ansi'], $root, $context, 'rollback_bootstrap_smoke');
    }

    /** @return array<string, int> */
    private function migrationRows(): array
    {
        try {
            return $this->database->connection()->table('migrations')->pluck('batch', 'migration')->map(fn (mixed $batch): int => (int) $batch)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param list<string> $arguments @param array<string, mixed> $context */
    private function command(array $arguments, string $root, array $context, string $name, bool $optional = false): string
    {
        if (isset($context['command_runner']) && is_callable($context['command_runner']) && app()->environment('testing')) {
            $value = ($context['command_runner'])($name, $arguments);
            if ($value === false && ! $optional) {
                throw new RuntimeException("El subproceso {$name} fallo.");
            }

            return is_string($value) ? $value : '';
        }
        if (! is_file($root.DIRECTORY_SEPARATOR.'artisan')) {
            if ($optional) {
                return '';
            }
            throw new RuntimeException('El root de apply no contiene un entrypoint Artisan.');
        }
        $process = new Process($arguments, $root, null, null, (int) config('waadby_operations.updates.process_timeout_seconds', 1800));
        $process->run();
        if (! $process->isSuccessful()) {
            if ($optional) {
                return '';
            }
            throw new RuntimeException("El subproceso {$name} fallo sin exponer su salida.");
        }

        return mb_substr($process->getOutput(), 0, 20000);
    }

    private function safeMessage(\Throwable $exception): string
    {
        $message = preg_replace('#(?:[A-Za-z]:[\\\\/]|/)[^\s]+#', '[path]', $exception->getMessage()) ?: 'La actualizacion fallo de forma segura.';

        return mb_substr($message, 0, 500);
    }

    private function removeTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
