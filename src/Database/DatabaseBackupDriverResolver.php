<?php

namespace Waadby\OperationsAgent\Database;

use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Waadby\OperationsAgent\Contracts\DatabaseBackupDriver;

class DatabaseBackupDriverResolver
{
    /** @param iterable<DatabaseBackupDriver> $drivers */
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly iterable $drivers,
    ) {}

    public function driverName(): string
    {
        return (string) $this->database->connection()->getDriverName();
    }

    public function resolve(): DatabaseBackupDriver
    {
        $name = $this->driverName();
        foreach ($this->drivers as $driver) {
            if ($driver->supports($name)) {
                return $driver;
            }
        }

        throw new RuntimeException("No existe un DatabaseBackupDriver para {$name}.");
    }
}
