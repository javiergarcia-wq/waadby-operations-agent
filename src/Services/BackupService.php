<?php

namespace Waadby\OperationsAgent\Services;

use Illuminate\Cache\CacheManager;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use RuntimeException;
use Waadby\OperationsAgent\Contracts\OperationsReporter;
use Waadby\OperationsAgent\Database\DatabaseBackupDriverResolver;
use Waadby\OperationsAgent\Support\ArchivePath;
use ZipArchive;

class BackupService
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly FilesystemManager $filesystems,
        private readonly DatabaseBackupDriverResolver $databaseDrivers,
        private readonly InventoryService $inventory,
        private readonly SensitiveConfigurationCipher $cipher,
        private readonly BackupVerifier $verifier,
        private readonly OperationsReporter $reporter,
    ) {}

    /** @return array<string, mixed> */
    public function create(string $type = 'operational', ?string $idempotencyKey = null, ?int $actorId = null): array
    {
        if (! in_array($type, ['operational', 'disaster'], true)) {
            throw new RuntimeException('El tipo de backup debe ser operational o disaster.');
        }
        if ($type === 'disaster' && ! $this->cipher->hasValidKey(config('waadby_operations.backup.key'))) {
            throw new RuntimeException('No se puede crear un backup de desastre sin una clave de backup configurada.');
        }
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extension ZIP es obligatoria para crear backups.');
        }

        $applicationCode = (string) config('waadby_operations.application.code');
        $lock = $this->cache->lock('waadby-operations:backup:'.hash('sha256', $applicationCode), 1800);
        if (! $lock->get()) {
            throw new RuntimeException('Ya existe un backup en curso para esta instalacion.');
        }

        try {
            return $this->createWhileLocked($type, $idempotencyKey, $actorId);
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function createWhileLocked(string $type, ?string $idempotencyKey, ?int $actorId): array
    {
        $operation = $this->reporter->beginOperation('backup', $idempotencyKey, $actorId);
        if (($operation['idempotent_replay'] ?? false) === true && isset($operation['summary']['backup_public_id'])) {
            $existing = $this->reporter->findArtifact((string) $operation['summary']['backup_public_id']);
            if ($existing) {
                return $existing;
            }
        }
        $inventory = $this->inventory->collect();
        $backupId = (string) Str::uuid();
        $diskName = (string) config('waadby_operations.backup.disk', 'local');
        $directory = trim((string) config('waadby_operations.backup.directory', 'waadby-operations/backups'), '/\\');
        $this->assertPrivateLocalDisk($diskName);
        $fileName = $this->fileName($inventory, $backupId);
        $storagePath = $directory.'/'.$fileName;
        $artifact = $this->reporter->createArtifact([
            'public_id' => $backupId,
            'operation_public_id' => $operation['public_id'],
            'backup_type' => $type,
            'status' => 'creating',
            'application_code' => $inventory['application_code'],
            'application_version' => $inventory['application_version'],
            'git_commit' => $inventory['git_commit'],
            'storage_disk' => $diskName,
            'storage_path' => $storagePath,
            'database_included' => false,
            'storage_included' => false,
            'configuration_included' => false,
            'code_snapshot_included' => false,
            'manifest' => [],
        ]);
        $context = ['operation_public_id' => $operation['public_id'], 'backup_public_id' => $backupId, 'backup_type' => $type];
        $this->reporter->audit('operations.backup.started', $context);

        $staging = $this->makeTemporaryDirectory($backupId);
        $temporaryZip = $staging.'.zip';

        try {
            $files = [];
            $database = ['included' => false, 'driver' => $inventory['database_driver'], 'file' => null];
            if ((bool) config('waadby_operations.database.enabled', true)) {
                $databasePath = $staging.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sql';
                $this->ensureDirectory(dirname($databasePath));
                $result = $this->databaseDrivers->resolve()->dump($databasePath);
                $files['database/database.sql'] = $databasePath;
                $database = ['included' => true, 'driver' => $result->driver, 'file' => 'database/database.sql'];
            }

            $storageFiles = $this->collectConfiguredPaths($staging, 'storage', (array) config('waadby_operations.backup.persistent_paths', []));
            $files = [...$files, ...$storageFiles];

            $configurationIncluded = false;
            if ($type === 'disaster') {
                $configuration = $this->sensitiveConfiguration();
                $encrypted = $this->cipher->encrypt($configuration, (string) config('waadby_operations.backup.key'));
                $configurationPath = $staging.DIRECTORY_SEPARATOR.'configuration.enc';
                file_put_contents($configurationPath, $encrypted, LOCK_EX);
                $files['configuration.enc'] = $configurationPath;
                $configurationIncluded = true;
                unset($configuration, $encrypted);
            }

            $codeFiles = [];
            if ($type === 'disaster' && (bool) config('waadby_operations.backup.include_code_snapshot', false)) {
                $codeFiles = $this->collectConfiguredPaths($staging, 'code', (array) config('waadby_operations.backup.code_paths', []));
                $files = [...$files, ...$codeFiles];
            }

            $manifest = [
                'manifest_version' => 1,
                'backup_id' => $backupId,
                'application_code' => $inventory['application_code'],
                'application_version' => $inventory['application_version'],
                'git_commit' => $inventory['git_commit'],
                'environment' => $inventory['environment'],
                'created_at' => now()->utc()->toIso8601String(),
                'backup_type' => $type,
                'database' => $database,
                'storage' => ['included' => $storageFiles !== [], 'files' => array_keys($storageFiles)],
                'configuration' => ['included' => $configurationIncluded, 'encrypted' => $configurationIncluded],
                'code_snapshot' => ['included' => $codeFiles !== [], 'files' => array_keys($codeFiles)],
                'migrations' => ['count' => $inventory['migration_count'], 'last' => $inventory['last_migration']],
                'checksums_file' => 'checksums.json',
            ];
            $manifestPath = $staging.DIRECTORY_SEPARATOR.'manifest.json';
            file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", LOCK_EX);
            $files['manifest.json'] = $manifestPath;

            $checksums = ['algorithm' => 'sha256', 'sha256' => []];
            foreach ($files as $archivePath => $sourcePath) {
                $checksums['sha256'][$archivePath] = hash_file('sha256', $sourcePath);
            }
            ksort($checksums['sha256']);
            $checksumsPath = $staging.DIRECTORY_SEPARATOR.'checksums.json';
            file_put_contents($checksumsPath, json_encode($checksums, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX);
            $files['checksums.json'] = $checksumsPath;

            $this->writeZip($temporaryZip, $files);
            $stream = fopen($temporaryZip, 'rb');
            if ($stream === false || ! $this->filesystems->disk($diskName)->put($storagePath, $stream)) {
                throw new RuntimeException('No se pudo guardar el backup en el disco privado configurado.');
            }
            if (is_resource($stream)) {
                fclose($stream);
            }

            $absolutePath = $this->filesystems->disk($diskName)->path($storagePath);
            $size = filesize($absolutePath);
            $sha256 = hash_file('sha256', $absolutePath);
            $attributes = [
                'status' => 'created',
                'absolute_path' => $absolutePath,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'database_included' => $database['included'],
                'storage_included' => $storageFiles !== [],
                'configuration_included' => $configurationIncluded,
                'code_snapshot_included' => $codeFiles !== [],
                'manifest' => $manifest,
            ];
            $this->reporter->updateArtifact($backupId, $attributes);
            $this->reporter->audit('operations.backup.created', [...$context, 'result' => 'created']);
            $this->reporter->finishOperation($operation['public_id'], 'succeeded', ['backup_public_id' => $backupId, 'size_bytes' => $size, 'sha256' => $sha256]);

            $result = [...$artifact, ...$attributes, 'public_id' => $backupId];
            if ((bool) config('waadby_operations.backup.auto_verify', true)) {
                $verification = $this->verifier->verify($backupId, $idempotencyKey ? $idempotencyKey.':verify' : null, $actorId);
                $result = [...$result, 'status' => 'verified', 'verification' => $verification];
            }

            return $result;
        } catch (\Throwable $exception) {
            $safe = $this->safeMessage($exception);
            $this->reporter->updateArtifact($backupId, ['status' => 'failed', 'failed_at' => now()]);
            $this->reporter->finishOperation($operation['public_id'], 'failed', [], 'backup_failed', $safe);
            $this->reporter->audit('operations.backup.failed', [...$context, 'result' => 'failed', 'error_code' => 'backup_failed']);
            throw new RuntimeException($safe, 0, $exception);
        } finally {
            $this->removeTree($staging);
            if (is_file($temporaryZip)) {
                @unlink($temporaryZip);
            }
        }
    }

    /** @param array<string|int, mixed> $configured
     * @return array<string, string>
     */
    private function collectConfiguredPaths(string $staging, string $prefix, array $configured): array
    {
        $files = [];
        foreach ($configured as $label => $source) {
            if (! is_string($source) || $source === '' || ! file_exists($source)) {
                continue;
            }
            $safeLabel = is_string($label) ? $this->sanitizeSegment($label) : $this->sanitizeSegment(basename($source));
            if (is_file($source)) {
                $archive = ArchivePath::assertSafe("{$prefix}/{$safeLabel}/".basename($source));
                if (! $this->isExcluded($source)) {
                    $target = $staging.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $archive);
                    $this->ensureDirectory(dirname($target));
                    copy($source, $target);
                    $files[$archive] = $target;
                }

                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $item) {
                if (! $item->isFile() || $item->isLink() || $this->isExcluded($item->getPathname())) {
                    continue;
                }
                $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen(rtrim($source, '/\\')))), '/');
                $archive = ArchivePath::assertSafe("{$prefix}/{$safeLabel}/{$relative}");
                $target = $staging.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $archive);
                $this->ensureDirectory(dirname($target));
                copy($item->getPathname(), $target);
                $files[$archive] = $target;
            }
        }

        ksort($files);

        return $files;
    }

    /** @return array<string, scalar|null> */
    private function sensitiveConfiguration(): array
    {
        $result = [];
        foreach ((array) config('waadby_operations.backup.sensitive_variables', []) as $name) {
            if (! is_string($name) || ! preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
                continue;
            }
            $value = Env::get($name);
            if (is_scalar($value) || $value === null) {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /** @param array<string, string> $files */
    private function writeZip(string $path, array $files): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el paquete ZIP de backup.');
        }
        try {
            foreach ($files as $archivePath => $source) {
                if (! $zip->addFile($source, ArchivePath::assertSafe($archivePath))) {
                    throw new RuntimeException("No se pudo incorporar {$archivePath} al backup.");
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function assertPrivateLocalDisk(string $disk): void
    {
        if (config("filesystems.disks.{$disk}.driver") !== 'local') {
            throw new RuntimeException('WAADBY Operations V1 requiere un disco Laravel local y privado.');
        }
        $root = (string) config("filesystems.disks.{$disk}.root");
        $normalizedRoot = str_replace('\\', '/', realpath($root) ?: $root);
        $normalizedPublic = str_replace('\\', '/', realpath(public_path()) ?: public_path());
        if ($root === '' || str_starts_with($normalizedRoot, $normalizedPublic)) {
            throw new RuntimeException('Los backups no pueden almacenarse dentro de public/.');
        }
    }

    /** @param array<string, mixed> $inventory */
    private function fileName(array $inventory, string $id): string
    {
        $parts = [$inventory['application_code'], $inventory['environment'], $inventory['application_version']];
        $safe = array_map(fn (mixed $part): string => trim(preg_replace('/[^a-zA-Z0-9._-]+/', '-', (string) $part), '-'), $parts);

        return implode('_', $safe).'_'.now()->utc()->format('Ymd\THis\Z').'_'.$id.'.zip';
    }

    private function sanitizeSegment(string $value): string
    {
        return trim(preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value), '-.') ?: 'data';
    }

    private function isExcluded(string $path): bool
    {
        $segments = preg_split('~[\\\\/]~', $path) ?: [];
        foreach ((array) config('waadby_operations.backup.excluded_names', []) as $excluded) {
            if (is_string($excluded) && in_array($excluded, $segments, true)) {
                return true;
            }
        }

        return ArchivePath::isPlainEnvironmentFile($path);
    }

    private function makeTemporaryDirectory(string $id): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'waadby-operations-'.$id;
        $this->ensureDirectory($directory);

        return $directory;
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("No se pudo crear el directorio temporal {$directory}.");
        }
    }

    private function removeTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }

    private function safeMessage(\Throwable $exception): string
    {
        $message = preg_replace('/(password|secret|token|key)\s*[=:]\s*\S+/i', '$1=[REDACTED]', $exception->getMessage());

        return mb_substr($message ?: 'La creacion del backup fallo.', 0, 500);
    }
}
