<?php

namespace Waadby\OperationsAgent\Database;

use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Waadby\OperationsAgent\Contracts\DatabaseBackupDriver;
use Waadby\OperationsAgent\Data\DatabaseBackupResult;

class SqliteDatabaseBackupDriver implements DatabaseBackupDriver
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function supports(string $driver): bool
    {
        return $driver === 'sqlite';
    }

    public function dump(string $destination): DatabaseBackupResult
    {
        $connection = $this->database->connection();
        $pdo = $connection->getPdo();
        $handle = fopen($destination, 'wb');

        if ($handle === false) {
            throw new RuntimeException('No se pudo crear el dump SQLite temporal.');
        }

        try {
            fwrite($handle, "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n");
            $objects = $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name")->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($objects as $object) {
                if ($object['type'] !== 'table') {
                    continue;
                }

                fwrite($handle, $object['sql'].";\n");
                $quotedTable = '"'.str_replace('"', '""', $object['name']).'"';
                $rows = $pdo->query("SELECT * FROM {$quotedTable}");
                while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) {
                    $values = array_map(fn (mixed $value): string => $value === null ? 'NULL' : $pdo->quote((string) $value), array_values($row));
                    fwrite($handle, "INSERT INTO {$quotedTable} VALUES(".implode(',', $values).");\n");
                }
            }

            foreach ($objects as $object) {
                if ($object['type'] !== 'table') {
                    fwrite($handle, $object['sql'].";\n");
                }
            }

            fwrite($handle, "COMMIT;\nPRAGMA foreign_keys=ON;\n");
        } finally {
            fclose($handle);
        }

        $size = filesize($destination);
        if (! is_int($size) || $size === 0) {
            throw new RuntimeException('El dump SQLite resulto vacio.');
        }

        return new DatabaseBackupResult('sqlite', basename($destination), $size);
    }
}
