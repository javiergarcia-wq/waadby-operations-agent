<?php

namespace Waadby\OperationsAgent\Restores;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Factory;
use RuntimeException;
use Waadby\OperationsAgent\Contracts\RestoreLifecycleHooks;

final class LaravelRestoreLifecycleHooks implements RestoreLifecycleHooks
{
    public function __construct(private readonly Kernel $artisan, private readonly Factory $http) {}

    public function quiesce(): void
    {
        $this->run('down', ['--retry' => 60, '--secret' => (string) config('waadby_operations.restores.maintenance_secret')]);
        $this->run('queue:restart');
    }

    public function migrateForward(): void
    {
        $this->run('migrate', ['--force' => true]);
    }

    public function smokeInternal(): void
    {
        if (! app('db')->connection()->getPdo()) {
            throw new RuntimeException('El smoke interno restore no pudo conectar con la base de datos.');
        }
    }

    public function resume(): void
    {
        $this->run('up');
    }

    public function smokeHttp(): void
    {
        $url = rtrim((string) config('app.url'), '/').(string) config('waadby_operations.restores.health_path', '/up');
        $response = $this->http->withoutRedirecting()->connectTimeout(3)->timeout(10)->get($url);
        if (! $response->successful()) {
            throw new RuntimeException('El healthcheck HTTP posterior al restore fallo.');
        }
    }

    /** @param array<string, mixed> $arguments */
    private function run(string $command, array $arguments = []): void
    {
        if ($this->artisan->call($command, $arguments) !== 0) {
            throw new RuntimeException("El hook restore {$command} fallo.");
        }
    }
}
