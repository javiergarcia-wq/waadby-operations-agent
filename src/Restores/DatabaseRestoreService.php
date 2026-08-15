<?php

namespace Waadby\OperationsAgent\Restores;

use Illuminate\Database\DatabaseManager;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Waadby\OperationsAgent\Support\OperationsPrivateStoragePathPolicy;

final class DatabaseRestoreService
{
    public function __construct(private readonly DatabaseManager $database, private readonly OperationsPrivateStoragePathPolicy $privateStorage) {}

    /** @return array<string, mixed> */
    public function validate(string $dump, string $driver): array
    {
        return match ($driver) {
            'sqlite' => $this->validateSqlite($dump),
            'mysql', 'mariadb' => $this->validateMySql($dump),
            default => throw new RuntimeException('Solo MySQL/MariaDB y SQLite admiten restore.'),
        };
    }

    public function apply(string $dump, string $driver): void
    {
        match ($driver) {
            'sqlite' => $this->applySqlite($dump),
            'mysql', 'mariadb' => $this->applyMySql($dump),
            default => throw new RuntimeException('Solo MySQL/MariaDB y SQLite admiten restore.'),
        };
    }

    /** @return array<string, mixed> */
    private function validateSqlite(string $dump): array
    {
        $directory = $this->privateStorage->prepareDirectory((string) config('waadby_operations.restores.validation_path'));
        $path = $this->privateStorage->assertFileWithinRoot($directory, $directory.DIRECTORY_SEPARATOR.'validate-'.bin2hex(random_bytes(8)).'.sqlite');
        try {
            $pdo = new PDO('sqlite:'.$path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec((string) file_get_contents($dump));
            $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
            if ($integrity !== 'ok') {
                throw new RuntimeException('La base SQLite restaurada no supera integrity_check.');
            }

            return ['driver' => 'sqlite', 'integrity' => 'ok'];
        } finally {
            unset($pdo);
            @unlink($path);
        }
    }

    /** @return array<string, mixed> */
    private function validateMySql(string $dump): array
    {
        $temporary = 'waadby_restore_validate_'.bin2hex(random_bytes(6));
        $this->assertDatabaseName($temporary);
        try {
            $this->mysqlStatement("CREATE DATABASE `{$temporary}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->mysqlImport($dump, $temporary);
            $this->mysqlStatement("CHECK TABLE `{$temporary}`.`migrations`", allowMissingMigrations: true);

            return ['driver' => 'mysql', 'validation_database' => 'temporary-cleaned'];
        } finally {
            try {
                $this->mysqlStatement("DROP DATABASE IF EXISTS `{$temporary}`");
            } catch (\Throwable) {
                throw new RuntimeException('No se pudo eliminar la base temporal de validacion restore.');
            }
        }
    }

    private function applySqlite(string $dump): void
    {
        $connection = (string) config('database.default');
        $configuredTarget = (string) config("database.connections.{$connection}.database");
        if ($configuredTarget === '' || $configuredTarget === ':memory:') {
            throw new RuntimeException('El destino SQLite local no es un fichero permitido de esta instalacion.');
        }
        $target = $this->privateStorage->prepareFile($configuredTarget);
        $temporary = $target.'.restore-'.bin2hex(random_bytes(5));
        $previous = $target.'.previous-'.bin2hex(random_bytes(5));
        try {
            $pdo = new PDO('sqlite:'.$temporary);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec((string) file_get_contents($dump));
            if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('El destino SQLite restaurado no supera integrity_check.');
            }
            unset($pdo);
            if (is_file($target) && ! @rename($target, $previous)) {
                throw new RuntimeException('No se pudo apartar el destino SQLite para el cutover.');
            }
            if (! @rename($temporary, $target)) {
                if (is_file($previous)) {
                    @rename($previous, $target);
                }
                throw new RuntimeException('No se pudo efectuar el cutover SQLite atomico.');
            }
            @unlink($previous);
        } finally {
            @unlink($temporary);
            if (! is_file($target) && is_file($previous)) {
                @rename($previous, $target);
            }
        }
    }

    private function applyMySql(string $dump): void
    {
        $connection = (string) config('database.default');
        $target = (string) config("database.connections.{$connection}.database");
        $this->assertDatabaseName($target);
        $this->mysqlStatement("DROP DATABASE IF EXISTS `{$target}`; CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->mysqlImport($dump, $target);
    }

    private function mysqlImport(string $dump, string $database): void
    {
        $defaults = $this->mysqlDefaultsFile();
        try {
            $process = new Process([(string) config('waadby_operations.database.mysql_binary', 'mysql'), '--defaults-extra-file='.$defaults, '--database='.$database, '--binary-mode=1'], timeout: (float) config('waadby_operations.restores.process_timeout_seconds', 1800));
            $stream = fopen($dump, 'rb');
            $process->setInput($stream);
            $process->run();
            fclose($stream);
            if (! $process->isSuccessful()) {
                throw new RuntimeException('La importacion MySQL restore fallo: '.mb_substr($process->getErrorOutput(), 0, 300));
            }
        } finally {
            @unlink($defaults);
        }
    }

    private function mysqlStatement(string $statement, bool $allowMissingMigrations = false): void
    {
        $defaults = $this->mysqlDefaultsFile();
        try {
            $process = new Process([(string) config('waadby_operations.database.mysql_binary', 'mysql'), '--defaults-extra-file='.$defaults, '--execute='.$statement], timeout: 120);
            $process->run();
            if (! $process->isSuccessful() && ! ($allowMissingMigrations && str_contains($process->getErrorOutput(), "doesn't exist"))) {
                throw new RuntimeException('La operacion MySQL restore fallo: '.mb_substr($process->getErrorOutput(), 0, 300));
            }
        } finally {
            @unlink($defaults);
        }
    }

    private function mysqlDefaultsFile(): string
    {
        $connection = (string) config('database.default');
        $settings = (array) config("database.connections.{$connection}");
        $directory = $this->privateStorage->prepareDirectory((string) config('waadby_operations.restores.validation_path'));
        $path = $this->privateStorage->assertFileWithinRoot($directory, $directory.DIRECTORY_SEPARATOR.'mysql-'.bin2hex(random_bytes(8)).'.cnf');
        $escape = static fn (mixed $value): string => str_replace(['\\', "\n", "\r", '"'], ['\\\\', '', '', '\\"'], (string) $value);
        $content = "[client]\nuser=\"{$escape($settings['username'] ?? '')}\"\npassword=\"{$escape($settings['password'] ?? '')}\"\nhost=\"{$escape($settings['host'] ?? '127.0.0.1')}\"\nport=\"{$escape($settings['port'] ?? 3306)}\"\nprotocol=tcp\n";
        if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) {
            throw new RuntimeException('No se pudo crear el defaults file privado MySQL.');
        }
        $this->privateStorage->protectFile($path);

        return $path;
    }

    private function assertDatabaseName(string $name): void
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new RuntimeException('El nombre de base de datos configurado no es seguro para restore.');
        }
    }
}
