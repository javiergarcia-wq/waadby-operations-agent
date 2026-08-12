<?php

namespace Waadby\OperationsAgent\Database;

use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Waadby\OperationsAgent\Contracts\DatabaseBackupDriver;
use Waadby\OperationsAgent\Data\DatabaseBackupResult;

class MySqlDatabaseBackupDriver implements DatabaseBackupDriver
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function supports(string $driver): bool
    {
        return in_array($driver, ['mysql', 'mariadb'], true);
    }

    public function dump(string $destination): DatabaseBackupResult
    {
        $connection = $this->database->connection();
        $config = $connection->getConfig();
        $configured = (string) config('waadby_operations.database.mysqldump_binary', 'mysqldump');
        $binary = is_file($configured) ? $configured : (new ExecutableFinder)->find($configured);

        if (! $binary) {
            throw new RuntimeException('No se encontro mysqldump; el backup de base de datos no puede declararse correcto.');
        }

        $optionFile = tempnam(sys_get_temp_dir(), 'waadby-db-');
        if ($optionFile === false) {
            throw new RuntimeException('No se pudo preparar el archivo temporal seguro para mysqldump.');
        }

        try {
            @chmod($optionFile, 0600);
            $contents = "[client]\n";
            foreach (['user' => 'username', 'password' => 'password', 'host' => 'host', 'port' => 'port'] as $option => $key) {
                if (isset($config[$key]) && $config[$key] !== '') {
                    $value = str_replace(['\\', "\n", "\r"], ['\\\\', '', ''], (string) $config[$key]);
                    $contents .= "{$option}={$value}\n";
                }
            }
            file_put_contents($optionFile, $contents, LOCK_EX);
            unset($contents, $value);

            $process = new Process([
                $binary,
                "--defaults-extra-file={$optionFile}",
                '--single-transaction',
                '--quick',
                '--routines',
                '--events',
                '--triggers',
                '--set-gtid-purged=OFF',
                '--result-file='.$destination,
                (string) $config['database'],
            ]);
            $process->setTimeout(900);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('mysqldump fallo: '.trim($process->getErrorOutput() ?: 'sin diagnostico adicional'));
            }
        } finally {
            if (is_file($optionFile)) {
                file_put_contents($optionFile, str_repeat("\0", max(1, (int) filesize($optionFile))), LOCK_EX);
                @unlink($optionFile);
            }
        }

        $size = filesize($destination);
        if (! is_int($size) || $size === 0) {
            throw new RuntimeException('mysqldump no produjo un archivo utilizable.');
        }

        return new DatabaseBackupResult('mysql', basename($destination), $size);
    }
}
