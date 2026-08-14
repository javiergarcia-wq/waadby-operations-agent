<?php

namespace Waadby\OperationsAgent\Updates;

use RuntimeException;
use ZipArchive;

final class ReleasePackageVerifier
{
    public const FILES_MANIFEST = 'waadby-release-files.json';

    public function __construct(private readonly ReleaseSignatureService $signatures, private readonly ReleasePathPolicy $paths) {}

    /** @param array<string, mixed> $manifest @param array<string, mixed> $signature
     * @return array{files:list<array{path:string,sha256:string,size:int,operation:string}>,package_sha256:string,files_manifest_sha256:string}
     */
    public function verify(array $manifest, array $signature, string $packagePath): array
    {
        if (($manifest['manifest_version'] ?? null) !== 2) {
            throw new RuntimeException('Solo Release Manifest V2 puede aplicarse.');
        }
        $this->signatures->verify($manifest, $signature);
        if (! is_file($packagePath) || ! is_readable($packagePath)) {
            throw new RuntimeException('No se encontro el package privado del release.');
        }
        $size = filesize($packagePath);
        if (! is_int($size) || $size > (int) config('waadby_operations.updates.maximum_package_bytes', 268435456)) {
            throw new RuntimeException('El package supera el limite de bytes permitido.');
        }
        $packageSha = hash_file('sha256', $packagePath);
        if (! is_string($manifest['package_sha256'] ?? null) || ! hash_equals(strtolower($manifest['package_sha256']), $packageSha)) {
            throw new RuntimeException('El SHA-256 del package no coincide.');
        }

        $zip = new ZipArchive;
        if ($zip->open($packagePath, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('El package ZIP no supera la comprobacion de integridad.');
        }
        try {
            if ($zip->numFiles > (int) config('waadby_operations.updates.maximum_files', 20000) + 1) {
                throw new RuntimeException('El package supera el numero maximo de ficheros.');
            }
            $seen = [];
            $uncompressed = 0;
            $entryNames = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if (! is_array($stat) || ! is_string($stat['name'] ?? null)) {
                    throw new RuntimeException('El ZIP contiene una entrada ilegible.');
                }
                $name = $stat['name'];
                $this->paths->assertSafe($name, allowUpdaterRuntime: true);
                $caseKey = strtolower($name);
                if (isset($seen[$caseKey])) {
                    throw new RuntimeException('El ZIP contiene rutas duplicadas o una colision de mayusculas.');
                }
                $seen[$caseKey] = true;
                $sizeEntry = (int) ($stat['size'] ?? 0);
                if ($sizeEntry > (int) config('waadby_operations.updates.maximum_file_bytes', 67108864)) {
                    throw new RuntimeException('Un fichero del release supera el limite individual.');
                }
                $uncompressed += $sizeEntry;
                if ($uncompressed > (int) config('waadby_operations.updates.maximum_uncompressed_bytes', 536870912)) {
                    throw new RuntimeException('El package supera el limite descomprimido.');
                }
                $attributes = 0;
                $opsys = 0;
                if ($zip->getExternalAttributesIndex($index, $opsys, $attributes) && $opsys === ZipArchive::OPSYS_UNIX) {
                    $mode = ($attributes >> 16) & 0170000;
                    if (! in_array($mode, [0, 0100000], true)) {
                        throw new RuntimeException('El ZIP contiene symlinks, devices o entradas no regulares.');
                    }
                }
                $entryNames[$name] = true;
            }

            $raw = $zip->getFromName(self::FILES_MANIFEST);
            if (! is_string($raw)) {
                throw new RuntimeException('El package no contiene waadby-release-files.json.');
            }
            $filesSha = hash('sha256', $raw);
            if (! is_string($manifest['package']['files_manifest_sha256'] ?? null) || ! hash_equals(strtolower($manifest['package']['files_manifest_sha256']), $filesSha)) {
                throw new RuntimeException('El SHA-256 del files manifest no coincide.');
            }
            $files = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($files) || ! array_is_list($files)) {
                throw new RuntimeException('El files manifest debe ser una lista JSON.');
            }
            $listed = [self::FILES_MANIFEST => true];
            foreach ($files as $item) {
                $keys = is_array($item) ? array_keys($item) : [];
                sort($keys, SORT_STRING);
                if (! is_array($item) || $keys !== ['operation', 'path', 'sha256', 'size']) {
                    throw new RuntimeException('Una entrada del files manifest no cumple el contrato estricto.');
                }
                $path = $this->paths->assertSafe(is_string($item['path']) ? $item['path'] : '');
                if (! in_array($item['operation'], ['replace', 'delete'], true) || ! is_int($item['size']) || $item['size'] < 0 || ! is_string($item['sha256']) || ! preg_match('/^[a-f0-9]{64}$/i', $item['sha256'])) {
                    throw new RuntimeException('Una operacion del files manifest no es valida.');
                }
                if (isset($listed[strtolower($path)])) {
                    throw new RuntimeException('El files manifest contiene rutas duplicadas.');
                }
                $listed[strtolower($path)] = true;
                $exists = isset($entryNames[$path]);
                if ($item['operation'] === 'replace') {
                    if (! $exists) {
                        throw new RuntimeException('Falta un fichero replace declarado en el ZIP.');
                    }
                    $contents = $zip->getFromName($path);
                    if (! is_string($contents) || strlen($contents) !== $item['size'] || ! hash_equals(strtolower($item['sha256']), hash('sha256', $contents))) {
                        throw new RuntimeException('Un fichero del package no coincide con su size/SHA-256.');
                    }
                } elseif ($exists) {
                    throw new RuntimeException('Una operacion delete no puede incluir contenido en el ZIP.');
                }
            }
            foreach (array_keys($entryNames) as $entry) {
                if (! isset($listed[strtolower($entry)])) {
                    throw new RuntimeException('El ZIP contiene un fichero no declarado.');
                }
            }

            return ['files' => $files, 'package_sha256' => $packageSha, 'files_manifest_sha256' => $filesSha];
        } catch (\JsonException $exception) {
            throw new RuntimeException('El files manifest no contiene JSON valido.', 0, $exception);
        } finally {
            $zip->close();
        }
    }

    /** @param list<array{path:string,sha256:string,size:int,operation:string}> $files */
    public function extract(string $packagePath, string $destination, array $files): void
    {
        if (! is_dir($destination) && ! mkdir($destination, 0700, true) && ! is_dir($destination)) {
            throw new RuntimeException('No se pudo crear staging privado para el release.');
        }
        $zip = new ZipArchive;
        if ($zip->open($packagePath, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('No se pudo abrir el package verificado.');
        }
        try {
            foreach ($files as $file) {
                if ($file['operation'] !== 'replace') {
                    continue;
                }
                $contents = $zip->getFromName($file['path']);
                if (! is_string($contents)) {
                    throw new RuntimeException('No se pudo extraer un fichero verificado.');
                }
                $target = $destination.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file['path']);
                if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0700, true) && ! is_dir(dirname($target))) {
                    throw new RuntimeException('No se pudo crear un directorio de staging.');
                }
                if (file_put_contents($target, $contents, LOCK_EX) !== strlen($contents)) {
                    throw new RuntimeException('No se pudo escribir un fichero completo en staging.');
                }
                @chmod($target, 0600);
            }
        } finally {
            $zip->close();
        }
    }
}
