<?php

namespace Waadby\OperationsAgent\Contracts;

use Waadby\OperationsAgent\Data\DatabaseBackupResult;

interface DatabaseBackupDriver
{
    public function supports(string $driver): bool;

    public function dump(string $destination): DatabaseBackupResult;
}
