<?php

namespace Waadby\OperationsAgent\Data;

final readonly class DatabaseBackupResult
{
    public function __construct(
        public string $driver,
        public string $file,
        public int $size,
    ) {}
}
