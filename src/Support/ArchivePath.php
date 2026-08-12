<?php

namespace Waadby\OperationsAgent\Support;

use RuntimeException;

final class ArchivePath
{
    public static function assertSafe(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        if ($normalized === '' || str_contains($normalized, "\0") || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized)) {
            throw new RuntimeException("Ruta insegura en el backup: {$path}");
        }

        $segments = explode('/', $normalized);
        if (in_array('..', $segments, true) || in_array('', $segments, true)) {
            throw new RuntimeException("Ruta insegura en el backup: {$path}");
        }

        return implode('/', array_filter($segments, fn (string $segment): bool => $segment !== '.'));
    }

    public static function isPlainEnvironmentFile(string $path): bool
    {
        return basename(str_replace('\\', '/', $path)) === '.env';
    }
}
