<?php

namespace Waadby\OperationsAgent\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Waadby\OperationsAgent\Vault\VaultEnvelopeCipher;

final class VaultDecryptFileCommand extends Command
{
    protected $signature = 'waadby:operations:vault:decrypt-file {file : Archivo .wbyvault local} {--output= : ZIP privado de salida} {--force : Permite sobrescribir el output}';

    protected $description = 'Descifra un envelope Vault a un ZIP sin extraerlo y sin depender de la base de datos';

    public function handle(VaultEnvelopeCipher $cipher): int
    {
        $input = $this->absolute((string) $this->argument('file'));
        $configuredOutput = $this->option('output');
        if (! is_string($configuredOutput) || $configuredOutput === '') {
            $this->error('Debe indicar --output=<backup.zip>.');

            return self::FAILURE;
        }
        $output = $this->absolute($configuredOutput);
        if ($this->insidePublic($output)) {
            $this->error('El output descifrado no puede escribirse dentro de public/.');

            return self::FAILURE;
        }
        if (file_exists($output) && ! $this->option('force')) {
            $this->error('El output ya existe; use --force únicamente tras verificar el destino.');

            return self::FAILURE;
        }
        if (! is_dir(dirname($output)) && ! mkdir(dirname($output), 0700, true) && ! is_dir(dirname($output))) {
            $this->error('No se pudo crear el directorio privado de salida.');

            return self::FAILURE;
        }
        $temporary = $output.'.'.bin2hex(random_bytes(6)).'.tmp';
        $source = is_file($input) ? fopen($input, 'rb') : false;
        $destination = fopen($temporary, 'x+b');
        if ($source === false || $destination === false) {
            $this->close($source);
            $this->close($destination);
            @unlink($temporary);
            $this->error('No se pudieron abrir los streams de recuperación Vault.');

            return self::FAILURE;
        }
        @chmod($temporary, 0600);
        $failure = null;
        try {
            $result = $cipher->decrypt($source, $destination, (string) config('waadby_operations.vault.key'));
            fflush($destination);
        } catch (Throwable $exception) {
            $failure = $exception->getMessage();
        } finally {
            fclose($source);
            fclose($destination);
        }
        if ($failure !== null) {
            @unlink($temporary);
            $this->error($failure);

            return self::FAILURE;
        }
        if (file_exists($output) && ! @unlink($output)) {
            @unlink($temporary);
            $this->error('No se pudo reemplazar el output solicitado.');

            return self::FAILURE;
        }
        if (! @rename($temporary, $output)) {
            @unlink($temporary);
            $this->error('No se pudo publicar atómicamente el ZIP descifrado.');

            return self::FAILURE;
        }
        @chmod($output, 0600);
        $this->info('ZIP descifrado y verificado: '.$result['source_size'].' bytes · SHA-256 '.$result['source_sha256']);

        return self::SUCCESS;
    }

    private function absolute(string $path): string
    {
        return preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) ? $path : getcwd().DIRECTORY_SEPARATOR.$path;
    }

    private function insidePublic(string $path): bool
    {
        $public = rtrim(str_replace('\\', '/', realpath(public_path()) ?: public_path()), '/').'/';
        $candidate = str_replace('\\', '/', dirname($path)).'/';

        return str_starts_with($candidate, $public);
    }

    private function close(mixed $resource): void
    {
        if (is_resource($resource)) {
            fclose($resource);
        }
    }
}
