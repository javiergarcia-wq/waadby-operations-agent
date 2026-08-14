<?php

namespace Waadby\OperationsAgent;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Waadby\OperationsAgent\Console\Commands\BackupCommand;
use Waadby\OperationsAgent\Console\Commands\BackupVerifyCommand;
use Waadby\OperationsAgent\Console\Commands\EnrollCommand;
use Waadby\OperationsAgent\Console\Commands\InventoryCommand;
use Waadby\OperationsAgent\Console\Commands\RegisterSelfCommand;
use Waadby\OperationsAgent\Console\Commands\ReleaseBuildCommand;
use Waadby\OperationsAgent\Console\Commands\RemoteDisableCommand;
use Waadby\OperationsAgent\Console\Commands\RestorePreflightCommand;
use Waadby\OperationsAgent\Console\Commands\UpdatePreflightCommand;
use Waadby\OperationsAgent\Console\Commands\VaultDecryptFileCommand;
use Waadby\OperationsAgent\Console\Commands\VaultVerifyFileCommand;
use Waadby\OperationsAgent\Contracts\OperationsReporter;
use Waadby\OperationsAgent\Contracts\OperationsRuntime;
use Waadby\OperationsAgent\Database\DatabaseBackupDriverResolver;
use Waadby\OperationsAgent\Database\MySqlDatabaseBackupDriver;
use Waadby\OperationsAgent\Database\SqliteDatabaseBackupDriver;
use Waadby\OperationsAgent\Services\LocalOperationsRuntime;
use Waadby\OperationsAgent\Support\FilesystemOperationsReporter;

class OperationsAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/waadby_operations.php', 'waadby_operations');
        $this->app->singleton(OperationsReporter::class, FilesystemOperationsReporter::class);
        $this->app->singleton(DatabaseBackupDriverResolver::class, fn ($app) => new DatabaseBackupDriverResolver(
            $app['db'],
            [new SqliteDatabaseBackupDriver($app['db']), new MySqlDatabaseBackupDriver($app['db'])],
        ));
        $this->app->singleton(OperationsRuntime::class, LocalOperationsRuntime::class);
    }

    public function boot(): void
    {
        RateLimiter::for('waadby-operations-agent', fn (Request $request): Limit => Limit::perMinute(
            (int) config('waadby_operations.remote_agent.rate_limit_per_minute', 120),
        )->by((string) $request->ip()));
        RateLimiter::for('waadby-operations-agent-mutations', fn (Request $request): Limit => Limit::perMinute(
            (int) config('waadby_operations.remote_agent.mutation_rate_limit_per_minute', 20),
        )->by((string) $request->ip()));
        RateLimiter::for('waadby-operations-agent-export', fn (Request $request): Limit => Limit::perMinute(
            (int) config('waadby_operations.remote_agent.export_rate_limit_per_minute', 10),
        )->by((string) $request->ip()));

        $this->loadRoutesFrom(__DIR__.'/../routes/remote.php');
        $this->publishes([
            __DIR__.'/../config/waadby_operations.php' => config_path('waadby_operations.php'),
        ], 'waadby-operations-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RegisterSelfCommand::class,
                EnrollCommand::class,
                RemoteDisableCommand::class,
                ReleaseBuildCommand::class,
                InventoryCommand::class,
                BackupCommand::class,
                BackupVerifyCommand::class,
                RestorePreflightCommand::class,
                UpdatePreflightCommand::class,
                VaultVerifyFileCommand::class,
                VaultDecryptFileCommand::class,
            ]);
        }
    }
}
