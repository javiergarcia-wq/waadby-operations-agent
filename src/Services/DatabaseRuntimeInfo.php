<?php

namespace Waadby\OperationsAgent\Services;

use Illuminate\Database\DatabaseManager;

class DatabaseRuntimeInfo
{
    public function __construct(private readonly DatabaseManager $database) {}

    /** @return array{driver: ?string, version: ?string, raw_version: ?string} */
    public function inspect(): array
    {
        $driver = $this->configuredDriver();
        $rawVersion = null;

        try {
            $row = match ($driver) {
                'sqlite' => $this->database->selectOne('select sqlite_version() as version'),
                'mysql', 'mariadb', 'pgsql' => $this->database->selectOne('select version() as version'),
                default => null,
            };
            $rawVersion = $row && isset($row->version) ? (string) $row->version : null;
        } catch (\Throwable) {
            // Version discovery is fail-closed at the caller when it is required.
        }

        return [
            'driver' => $driver,
            'version' => self::normalizeVersion($rawVersion),
            'raw_version' => $rawVersion,
        ];
    }

    public function configuredDriver(): ?string
    {
        $connection = (string) config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        return is_string($driver) && $driver !== '' ? strtolower($driver) : null;
    }

    public static function normalizeVersion(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if (stripos($value, 'mariadb') !== false && preg_match('/([0-9]+(?:\.[0-9]+){1,3})(?=[^0-9]*-?MariaDB)/i', $value, $match)) {
            return $match[1];
        }

        return preg_match('/[0-9]+(?:\.[0-9]+){1,3}/', $value, $match) ? $match[0] : null;
    }
}
