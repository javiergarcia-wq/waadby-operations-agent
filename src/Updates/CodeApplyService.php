<?php

namespace Waadby\OperationsAgent\Updates;

use RuntimeException;

final class CodeApplyService
{
    public function __construct(private readonly ReleasePathPolicy $paths, private readonly UpdateDestinationPathPolicy $destinations) {}

    /** @param list<array{path:string,sha256:string,size:int,operation:string}> $files */
    public function apply(string $root, string $staging, array $files): void
    {
        foreach ($files as $file) {
            $relative = $this->paths->assertSafe($file['path']);
            $target = $this->destinations->resolveFile($root, $relative);
            if ($file['operation'] === 'delete') {
                $target = $this->destinations->resolveFile($root, $relative);
                if (is_file($target) && ! @unlink($target)) {
                    throw new RuntimeException('No se pudo borrar un fichero autorizado del release.');
                }

                continue;
            }
            $source = rtrim($staging, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (! is_file($source) || ! hash_equals(strtolower($file['sha256']), hash_file('sha256', $source))) {
                throw new RuntimeException('Staging no contiene el fichero replace verificado.');
            }
            if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0755, true) && ! is_dir(dirname($target))) {
                throw new RuntimeException('No se pudo crear el directorio de destino del release.');
            }
            $target = $this->destinations->resolveFile($root, $relative);
            $temporary = $target.'.waadby-update-'.bin2hex(random_bytes(6));
            $input = fopen($source, 'rb');
            $output = fopen($temporary, 'xb');
            if ($input === false || $output === false) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                @unlink($temporary);
                throw new RuntimeException('No se pudo abrir un fichero temporal de apply.');
            }
            try {
                if (stream_copy_to_stream($input, $output) !== $file['size'] || ! fflush($output)) {
                    throw new RuntimeException('La escritura temporal del release quedo incompleta.');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            if (! hash_equals(strtolower($file['sha256']), hash_file('sha256', $temporary))) {
                @unlink($temporary);
                throw new RuntimeException('El fichero temporal no supera SHA-256.');
            }
            $target = $this->destinations->resolveFile($root, $relative);
            if (is_file($target) && ! @unlink($target)) {
                @unlink($temporary);
                throw new RuntimeException('No se pudo sustituir el fichero anterior.');
            }
            if (! @rename($temporary, $target) || ! hash_equals(strtolower($file['sha256']), hash_file('sha256', $target))) {
                @unlink($temporary);
                throw new RuntimeException('El fichero aplicado no supera SHA-256.');
            }
        }
    }
}
