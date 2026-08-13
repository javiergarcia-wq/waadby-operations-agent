<?php

namespace Waadby\OperationsAgent\Remote;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class EnrollmentStore
{
    public function __construct(private readonly Filesystem $files) {}

    /** @return array<string, mixed>|null */
    public function get(): ?array
    {
        $path = $this->path();
        if (! $this->files->exists($path)) {
            return null;
        }

        try {
            $value = json_decode($this->files->get($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('El estado local de enrollment no es válido.');
        }

        return is_array($value) ? $value : null;
    }

    /** @param array<string, mixed> $identity */
    public function put(array $identity): void
    {
        $directory = dirname($this->path());
        $this->files->ensureDirectoryExists($directory, 0700, true);
        $temporary = $this->path().'.'.bin2hex(random_bytes(6)).'.tmp';
        $json = json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->files->put($temporary, $json, true);
        @chmod($temporary, 0600);
        if (! @rename($temporary, $this->path())) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo guardar atómicamente el enrollment local.');
        }
    }

    public function disable(): void
    {
        $identity = $this->get() ?? [];
        $identity['locally_disabled_at'] = now()->toIso8601String();
        $this->put($identity);
    }

    public function isReady(): bool
    {
        $identity = $this->get();

        return is_array($identity)
            && ! isset($identity['locally_disabled_at'])
            && filled($identity['installation_id'] ?? null)
            && filled($identity['access_origin'] ?? null)
            && is_array($identity['jwks'] ?? null);
    }

    public function path(): string
    {
        return rtrim((string) config('waadby_operations.remote_agent.state_path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'enrollment.json';
    }
}
