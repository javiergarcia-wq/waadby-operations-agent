<?php

namespace Waadby\OperationsAgent\Restores;

use RuntimeException;
use Waadby\OperationsAgent\Support\ArchivePath;
use Waadby\OperationsAgent\Support\OperationsPrivateStoragePathPolicy;
use ZipArchive;

final class RestoreArchiveStage
{
    public function __construct(private readonly OperationsPrivateStoragePathPolicy $privateStorage) {}

    public function extract(string $archive, string $restoreId): string
    {
        if (! is_file($archive)) {
            throw new RuntimeException('El archivo restore no existe.');
        }
        $root = $this->privateStorage->prepareDirectory((string) config('waadby_operations.restores.staging_path'));
        $stage = $this->privateStorage->prepareChildDirectory($root, $restoreId);
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('No se pudo abrir el ZIP restore.');
        }
        $maximumFiles = (int) config('waadby_operations.restores.maximum_files', 100000);
        $maximumBytes = (int) config('waadby_operations.restores.maximum_uncompressed_bytes', 10737418240);
        $total = 0;
        try {
            if ($zip->numFiles > $maximumFiles) {
                throw new RuntimeException('El backup supera el limite de ficheros restore.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = ArchivePath::assertSafe((string) ($stat['name'] ?? ''));
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $total += (int) ($stat['size'] ?? 0);
                if ($total > $maximumBytes) {
                    throw new RuntimeException('El backup supera el limite descomprimido restore.');
                }
                $target = $this->privateStorage->assertFileWithinRoot($stage, $stage.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name));
                $directory = dirname($target);
                if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                    throw new RuntimeException('No se pudo crear el staging restore.');
                }
                $input = $zip->getStream($name);
                if (! is_resource($input)) {
                    throw new RuntimeException('No se pudo leer una entrada restore.');
                }
                if (is_file($target)) {
                    $context = hash_init('sha256');
                    hash_update_stream($context, $input);
                    fclose($input);
                    if (! hash_equals(hash_file('sha256', $target), hash_final($context))) {
                        throw new RuntimeException('El staging restore existente no coincide con el origen revalidado.');
                    }

                    continue;
                }
                $output = fopen($target, 'xb');
                if (! is_resource($output)) {
                    throw new RuntimeException('No se pudo extraer una entrada restore.');
                }
                try {
                    if (stream_copy_to_stream($input, $output) !== (int) $stat['size']) {
                        throw new RuntimeException('Una entrada restore quedo truncada.');
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
                $this->privateStorage->protectFile($target);
            }
        } finally {
            $zip->close();
        }

        return $stage;
    }

    public function cleanup(?string $stage): void
    {
        if (! is_string($stage) || ! is_dir($stage)) {
            return;
        }
        $root = (string) config('waadby_operations.restores.staging_path');
        $canonical = $this->privateStorage->assertExistingDirectoryWithinRoot($root, $stage);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($canonical, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || ($entry->isDir() ? ! @rmdir($entry->getPathname()) : ! @unlink($entry->getPathname()))) {
                throw new RuntimeException('No se pudo limpiar completamente el staging restore privado.');
            }
        }
        if (! @rmdir($canonical)) {
            throw new RuntimeException('No se pudo retirar el staging restore privado.');
        }
    }
}
