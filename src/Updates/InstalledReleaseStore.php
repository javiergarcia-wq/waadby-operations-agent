<?php

namespace Waadby\OperationsAgent\Updates;

use RuntimeException;

final class InstalledReleaseStore
{
    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return null;
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($value)
            || ($value['application_code'] ?? null) !== config('waadby_operations.application.code')
            || ! is_string($value['version'] ?? null)
            || ! is_string($value['source_commit'] ?? null)
            || ! preg_match('/^[a-f0-9]{40}$/i', $value['source_commit'])) {
            return null;
        }

        return $value;
    }

    /** @param array<string, mixed> $state */
    public function write(array $state): void
    {
        $required = ['application_code', 'version', 'source_commit', 'release_public_id', 'applied_at'];
        foreach ($required as $key) {
            if (! isset($state[$key]) || ! is_string($state[$key]) || $state[$key] === '') {
                throw new RuntimeException("El estado instalado no contiene {$key}.");
            }
        }
        $path = $this->path();
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el directorio privado del estado instalado.');
        }
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(8));
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo escribir el estado instalado completo.');
        }
        @chmod($temporary, 0600);
        if (! @rename($temporary, $path)) {
            @unlink($path);
            if (! @rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('No se pudo publicar atomically el estado instalado.');
            }
        }
    }

    public function path(): string
    {
        return (string) config('waadby_operations.updates.state_path', storage_path('app/private/waadby-operations/installed-release.json'));
    }
}
