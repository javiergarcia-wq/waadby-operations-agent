<?php

namespace Waadby\OperationsAgent\Remote;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Waadby\OperationsAgent\Support\OperationsPrivateStoragePathPolicy;

final class EnrollmentStore
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly OperationsPrivateStoragePathPolicy $privateStorage,
    ) {}

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
        $path = $this->path();
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $json = json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->files->put($temporary, $json, true);
        $this->privateStorage->protectFile($temporary);
        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo guardar atómicamente el enrollment local.');
        }
        $this->privateStorage->protectFile($path);
    }

    public function disable(): void
    {
        $identity = $this->get() ?? [];
        $identity['locally_disabled_at'] = now()->toIso8601String();
        $this->put($identity);
    }

    public function isReady(): bool
    {
        try {
            $identity = $this->get();
        } catch (RuntimeException) {
            return false;
        }
        $applicationCode = (string) config('waadby_operations.application.code');
        $environment = (string) config('waadby_operations.application.environment');

        return is_array($identity)
            && ! isset($identity['locally_disabled_at'])
            && filled($identity['installation_id'] ?? null)
            && filled($identity['access_origin'] ?? null)
            && is_array($identity['jwks'] ?? null)
            && $applicationCode !== ''
            && $environment !== ''
            && is_string($identity['application_code'] ?? null)
            && is_string($identity['environment'] ?? null)
            && hash_equals($applicationCode, $identity['application_code'])
            && hash_equals($environment, $identity['environment']);
    }

    public function path(): string
    {
        $root = $this->privateStorage->prepareDirectory((string) config('waadby_operations.remote_agent.state_path'));

        return $this->privateStorage->assertFileWithinRoot($root, $root.DIRECTORY_SEPARATOR.'enrollment.json');
    }
}
