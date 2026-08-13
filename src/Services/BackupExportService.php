<?php

namespace Waadby\OperationsAgent\Services;

use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waadby\OperationsAgent\Contracts\OperationsReporter;

final class BackupExportService
{
    public function __construct(private readonly OperationsReporter $reporter, private readonly FilesystemManager $filesystems) {}

    public function response(string $backupId, ?string $rangeHeader): StreamedResponse
    {
        $artifact = $this->reporter->findArtifact($backupId);
        if (! is_array($artifact) || ($artifact['public_id'] ?? null) !== $backupId) {
            throw new RuntimeException('backup_not_found', 404);
        }
        if (($artifact['status'] ?? null) !== 'verified') {
            throw new RuntimeException('backup_not_verified', 409);
        }
        $diskName = $artifact['storage_disk'] ?? null;
        $storagePath = $artifact['storage_path'] ?? null;
        if (! is_string($diskName) || ! is_string($storagePath) || $diskName === '' || $storagePath === '') {
            throw new RuntimeException('backup_unavailable', 409);
        }
        $disk = $this->filesystems->disk($diskName);
        if (! $disk->exists($storagePath)) {
            throw new RuntimeException('backup_unavailable', 409);
        }
        $size = (int) $disk->size($storagePath);
        $expectedSize = (int) ($artifact['size_bytes'] ?? -1);
        $expectedSha = (string) ($artifact['sha256'] ?? '');
        $source = $disk->readStream($storagePath);
        if (! is_resource($source)) {
            throw new RuntimeException('backup_unavailable', 409);
        }
        $hash = hash_init('sha256');
        hash_update_stream($hash, $source);
        $actualSha = hash_final($hash);
        fclose($source);
        if ($size !== $expectedSize || ! preg_match('/^[a-f0-9]{64}$/', $expectedSha) || ! hash_equals($expectedSha, $actualSha)) {
            throw new RuntimeException('backup_source_tampered', 409);
        }
        $offset = $this->offset($rangeHeader, $size);
        $status = filled($rangeHeader) ? 206 : 200;
        $headers = [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) ($size - $offset),
            'Accept-Ranges' => 'bytes',
            'X-Waadby-Backup-ID' => $backupId,
            'X-Waadby-Backup-SHA256' => $expectedSha,
            'X-Waadby-Backup-Size' => (string) $size,
            'ETag' => '"sha256-'.$expectedSha.'"',
            'Cache-Control' => 'no-store, private',
        ];
        if ($status === 206) {
            $headers['Content-Range'] = 'bytes '.$offset.'-'.($size - 1).'/'.$size;
        }

        return response()->stream(function () use ($disk, $storagePath, $offset): void {
            $stream = $disk->readStream($storagePath);
            if (! is_resource($stream)) {
                return;
            }
            try {
                if ($offset > 0 && @fseek($stream, $offset) !== 0) {
                    $remaining = $offset;
                    while ($remaining > 0 && ! feof($stream)) {
                        $discard = fread($stream, min(1048576, $remaining));
                        if ($discard === false || $discard === '') {
                            return;
                        }
                        $remaining -= strlen($discard);
                    }
                }
                while (! feof($stream)) {
                    $chunk = fread($stream, 1048576);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                    flush();
                }
            } finally {
                fclose($stream);
            }
        }, $status, $headers);
    }

    private function offset(?string $header, int $size): int
    {
        if ($header === null || $header === '') {
            return 0;
        }
        if (preg_match('/^bytes=(0|[1-9][0-9]*)-$/', $header, $matches) !== 1) {
            throw new RuntimeException('range_not_satisfiable', 416);
        }
        $offset = (int) $matches[1];
        if ($offset >= $size) {
            throw new RuntimeException('range_not_satisfiable', 416);
        }

        return $offset;
    }
}
