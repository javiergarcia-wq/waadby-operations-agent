<?php

namespace Waadby\OperationsAgent\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Waadby\OperationsAgent\Vault\VaultEnvelopeCipher;

final class VaultVerifyFileCommand extends Command
{
    protected $signature = 'waadby:operations:vault:verify-file {file : Archivo .wbyvault local}';

    protected $description = 'Verifica un envelope Vault sin depender de la base de datos de ACCESS';

    public function handle(VaultEnvelopeCipher $cipher): int
    {
        $path = $this->absolute((string) $this->argument('file'));
        $stream = is_file($path) ? fopen($path, 'rb') : false;
        if ($stream === false) {
            $this->error('No se pudo abrir el archivo Vault.');

            return self::FAILURE;
        }
        try {
            $result = $cipher->decrypt($stream, null, (string) config('waadby_operations.vault.key'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            fclose($stream);
        }
        $this->info('Envelope Vault verificado: '.$result['header']['source_backup_id'].' · '.$result['source_size'].' bytes · SHA-256 '.$result['source_sha256']);

        return self::SUCCESS;
    }

    private function absolute(string $path): string
    {
        return preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) ? $path : getcwd().DIRECTORY_SEPARATOR.$path;
    }
}
