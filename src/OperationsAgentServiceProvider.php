<?php

namespace Waadby\OperationsAgent;

use Illuminate\Support\ServiceProvider;
use Waadby\OperationsAgent\Console\Commands\BackupCommand;
use Waadby\OperationsAgent\Console\Commands\BackupVerifyCommand;
use Waadby\OperationsAgent\Console\Commands\EnrollCommand;
use Waadby\OperationsAgent\Console\Commands\InventoryCommand;
use Waadby\OperationsAgent\Console\Commands\RegisterSelfCommand;
use Waadby\OperationsAgent\Console\Commands\RemoteDisableCommand;
use Waadby\OperationsAgent\Console\Commands\RestorePreflightCommand;
use Waadby\OperationsAgent\Console\Commands\UpdatePreflightCommand;
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
        $this->loadRoutesFrom(__DIR__.'/../routes/remote.php');
        $this->publishes([
            __DIR__.'/../config/waadby_operations.php' => config_path('waadby_operations.php'),
        ], 'waadby-operations-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RegisterSelfCommand::class,
                EnrollCommand::class,
                RemoteDisableCommand::class,
                InventoryCommand::class,
                BackupCommand::class,
                BackupVerifyCommand::class,
                RestorePreflightCommand::class,
                UpdatePreflightCommand::class,
            ]);
        }
    }
}
