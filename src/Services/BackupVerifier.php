<?php

namespace Waadby\OperationsAgent\Services;

use RuntimeException;
use Waadby\OperationsAgent\Contracts\OperationsReporter;
use Waadby\OperationsAgent\Support\ArchivePath;
use ZipArchive;

class BackupVerifier
{
    public function __construct(private readonly OperationsReporter $reporter) {}

    /** @return array<string, mixed> */
    public function verify(string $reference, ?string $idempotencyKey = null, ?int $actorId = null): array
    {
        $artifact = $this->reporter->findArtifact($reference);
        if (! $artifact) {
            throw new RuntimeException('No se encontro el backup solicitado.');
        }

        $operation = $this->reporter->beginOperation('backup_verify', $idempotencyKey, $actorId);
        $artifactId = $artifact['public_id'] ?? null;
        if ($artifactId) {
            $this->reporter->updateArtifact($artifactId, ['status' => 'verifying']);
        }
        $this->reporter->audit('operations.backup.verification_started', $this->context($operation, $artifact));

        try {
            $result = $this->inspect(
                (string) ($artifact['absolute_path'] ?? $artifact['storage_path']),
                (string) config('waadby_operations.application.code'),
                $artifactId,
                $artifact['sha256'] ?? null,
            );

            if ($artifactId) {
                $this->reporter->updateArtifact($artifactId, ['status' => 'verified', 'verified_at' => now()]);
            }
            $this->reporter->finishOperation($operation['public_id'], 'succeeded', $result);
            $this->reporter->audit('operations.backup.verified', [...$this->context($operation, $artifact), 'result' => 'verified']);

            return [...$result, 'status' => 'verified'];
        } catch (\Throwable $exception) {
            if ($artifactId) {
                $this->reporter->updateArtifact($artifactId, ['status' => 'failed', 'failed_at' => now()]);
            }
            $safe = $this->safeMessage($exception);
            $this->reporter->finishOperation($operation['public_id'], 'failed', [], 'backup_verification_failed', $safe);
            $this->reporter->audit('operations.backup.failed', [...$this->context($operation, $artifact), 'result' => 'failed', 'error_code' => 'backup_verification_failed']);
            throw new RuntimeException($safe, 0, $exception);
        }
    }

    /** @return array<string, mixed> */
    public function inspect(string $path, string $expectedApplicationCode, ?string $expectedBackupId = null, ?string $expectedFinalSha = null): array
    {
        if (! is_file($path) || ($size = filesize($path)) === false || $size < 1) {
            throw new RuntimeException('El archivo de backup no existe o esta vacio.');
        }
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extension ZIP es obligatoria para verificar backups.');
        }
        if ($expectedFinalSha && ! hash_equals(strtolower($expectedFinalSha), hash_file('sha256', $path))) {
            throw new RuntimeException('El SHA-256 final del artefacto no coincide.');
        }

        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new RuntimeException('El paquete ZIP esta corrupto o no es legible.');
        }

        try {
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                $safeName = ArchivePath::assertSafe(rtrim($name, '/'));
                if (ArchivePath::isPlainEnvironmentFile($safeName)) {
                    throw new RuntimeException('El backup contiene un archivo .env en texto plano.');
                }

                if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                    $mode = ($attributes >> 16) & 0170000;
                    if ($mode === 0120000) {
                        throw new RuntimeException("El backup contiene un enlace simbolico no permitido: {$safeName}");
                    }
                }
                $entries[$safeName] = $index;
            }

            foreach (['manifest.json', 'checksums.json'] as $required) {
                if (! isset($entries[$required])) {
                    throw new RuntimeException("Falta {$required} en el backup.");
                }
            }

            $manifest = $this->decodeJson($zip->getFromIndex($entries['manifest.json']), 'manifest.json');
            $checksums = $this->decodeJson($zip->getFromIndex($entries['checksums.json']), 'checksums.json');
            $this->assertManifest($manifest, $expectedApplicationCode, $expectedBackupId);

            $database = $manifest['database'] ?? [];
            if (($database['included'] ?? false) === true) {
                $databaseFile = ArchivePath::assertSafe((string) ($database['file'] ?? ''));
                if (! isset($entries[$databaseFile]) || (int) $zip->statIndex($entries[$databaseFile])['size'] < 1) {
                    throw new RuntimeException('El dump de base de datos declarado falta o esta vacio.');
                }
            }

            $storage = $manifest['storage'] ?? [];
            if (($storage['included'] ?? false) === true) {
                foreach (($storage['files'] ?? []) as $file) {
                    $storageFile = ArchivePath::assertSafe((string) $file);
                    if (! isset($entries[$storageFile])) {
                        throw new RuntimeException("Falta un archivo persistente declarado: {$storageFile}");
                    }
                }
            }

            $configuration = $manifest['configuration'] ?? [];
            if (($configuration['included'] ?? false) === true) {
                if (($configuration['encrypted'] ?? false) !== true || ! isset($entries['configuration.enc'])) {
                    throw new RuntimeException('La configuracion de desastre no esta cifrada o falta configuration.enc.');
                }
            }

            $checksumMap = $checksums['sha256'] ?? null;
            if (! is_array($checksumMap) || $checksumMap === []) {
                throw new RuntimeException('checksums.json no contiene comprobaciones SHA-256.');
            }
            foreach ($checksumMap as $entry => $expected) {
                $entry = ArchivePath::assertSafe((string) $entry);
                if (! isset($entries[$entry])) {
                    throw new RuntimeException("Falta el componente declarado en checksums: {$entry}");
                }
                $contents = $zip->getFromIndex($entries[$entry]);
                if (! is_string($contents) || ! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/', $expected) || ! hash_equals($expected, hash('sha256', $contents))) {
                    throw new RuntimeException("Checksum incorrecto para {$entry}.");
                }
            }

            return [
                'compatible' => true,
                'backup_id' => $manifest['backup_id'],
                'application_code' => $manifest['application_code'],
                'backup_type' => $manifest['backup_type'],
                'size_bytes' => $size,
                'sha256' => hash_file('sha256', $path),
                'manifest' => $manifest,
                'checksums_valid' => true,
                'zip_integrity' => true,
            ];
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, mixed> */
    private function decodeJson(string|false $json, string $name): array
    {
        if (! is_string($json)) {
            throw new RuntimeException("No se pudo leer {$name}.");
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException("{$name} no contiene JSON valido.");
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("{$name} no contiene un objeto JSON.");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $manifest */
    private function assertManifest(array $manifest, string $applicationCode, ?string $backupId): void
    {
        foreach (['manifest_version', 'backup_id', 'application_code', 'application_version', 'environment', 'created_at', 'backup_type', 'database', 'storage', 'configuration', 'checksums_file'] as $field) {
            if (! array_key_exists($field, $manifest)) {
                throw new RuntimeException("El manifest no contiene {$field}.");
            }
        }
        if ($manifest['manifest_version'] !== 1 || ! in_array($manifest['backup_type'], ['operational', 'disaster'], true)) {
            throw new RuntimeException('La version o el tipo del manifest no es compatible.');
        }
        if (! hash_equals($applicationCode, (string) $manifest['application_code'])) {
            throw new RuntimeException('El application_code del backup no coincide con la aplicacion actual.');
        }
        if ($backupId && ! hash_equals($backupId, (string) $manifest['backup_id'])) {
            throw new RuntimeException('El backup_id del manifest no coincide con el artefacto.');
        }
        if ($manifest['checksums_file'] !== 'checksums.json') {
            throw new RuntimeException('El manifest no referencia checksums.json.');
        }
    }

    /** @param array<string, mixed> $operation
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    private function context(array $operation, array $artifact): array
    {
        return ['operation_public_id' => $operation['public_id'], 'backup_public_id' => $artifact['public_id'] ?? null];
    }

    private function safeMessage(\Throwable $exception): string
    {
        $message = preg_replace('/(password|secret|token|key)\s*[=:]\s*\S+/i', '$1=[REDACTED]', $exception->getMessage());

        return mb_substr($message ?: 'La verificacion del backup fallo.', 0, 500);
    }
}
