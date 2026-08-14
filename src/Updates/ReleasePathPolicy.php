<?php

namespace Waadby\OperationsAgent\Updates;

use RuntimeException;

final class ReleasePathPolicy
{
    private const PROTECTED_PREFIXES = [
        '.env', 'storage', 'public/storage', '.git', 'backups',
        'waadby-vault', 'vault', 'credentials', 'secrets', 'keys',
    ];

    private const UPDATER_RUNTIME_PREFIXES = [
        'packages/waadby-operations-agent/src/Updates',
        'packages/waadby-operations-agent/src/Jobs/ExecuteUpdateSession.php',
        'packages/waadby-operations-agent/src/Http/Controllers/RemoteUpdateController.php',
        'packages/waadby-operations-agent/src/Http/Middleware/VerifyRemoteOperationsRequest.php',
    ];

    public function assertSafe(string $path, bool $allowUpdaterRuntime = false): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new RuntimeException('El release contiene una ruta absoluta o no portable.');
        }
        $segments = explode('/', $path);
        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new RuntimeException('El release contiene path traversal.');
        }
        foreach ($segments as $segment) {
            $stem = strtoupper(rtrim(explode('.', $segment, 2)[0], ' .'));
            if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/', $stem)) {
                throw new RuntimeException('El release contiene un nombre reservado de Windows.');
            }
        }
        $normalized = strtolower(rtrim($path, '/'));
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            $prefix = strtolower($prefix);
            if ($normalized === $prefix || str_starts_with($normalized, $prefix.'/') || ($prefix === '.env' && str_starts_with($normalized, '.env.'))) {
                throw new RuntimeException('El release intenta modificar una ruta persistente o protegida.');
            }
        }
        if (! $allowUpdaterRuntime) {
            foreach (self::UPDATER_RUNTIME_PREFIXES as $prefix) {
                $prefix = strtolower($prefix);
                if ($normalized === $prefix || str_starts_with($normalized, $prefix.'/')) {
                    throw new RuntimeException('La primera version del updater bloquea cambios de su runtime critico.');
                }
            }
        }

        return $path;
    }
}
