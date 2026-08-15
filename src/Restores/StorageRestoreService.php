<?php

namespace Waadby\OperationsAgent\Restores;

use RuntimeException;
use Waadby\OperationsAgent\Support\OperationsPrivateStoragePathPolicy;

final class StorageRestoreService
{
    public function __construct(private readonly OperationsPrivateStoragePathPolicy $privateStorage) {}

    /** @param list<string> $declared */
    public function apply(string $stage, array $declared): void
    {
        $roots = (array) config('waadby_operations.backup.persistent_paths', []);
        $byLabel = [];
        foreach ($declared as $entry) {
            if (! preg_match('~^storage/([A-Za-z0-9_-]+)/(.+)$~', $entry, $match) || ! array_key_exists($match[1], $roots)) {
                throw new RuntimeException('El backup declara una raiz persistente no permitida.');
            }
            $byLabel[$match[1]][] = $match[2];
        }
        foreach ($byLabel as $label => $files) {
            $targetRoot = $this->privateStorage->prepareDirectory((string) $roots[$label]);
            $sourceRoot = $this->privateStorage->assertExistingDirectoryWithinRoot($stage, $stage.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.$label);
            $expected = [];
            foreach ($files as $relative) {
                $source = $this->privateStorage->assertFileWithinRoot($sourceRoot, $sourceRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative), true);
                $target = $this->privateStorage->assertFileWithinRoot($targetRoot, $targetRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
                if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0700, true) && ! is_dir(dirname($target))) {
                    throw new RuntimeException('No se pudo crear un directorio persistente restore.');
                }
                if (! copy($source, $target) || ! hash_equals(hash_file('sha256', $source), hash_file('sha256', $target))) {
                    throw new RuntimeException('No se pudo reconciliar un fichero persistente restore.');
                }
                $expected[str_replace('\\', '/', $relative)] = true;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($targetRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($targetRoot) + 1));
                if ($item->isLink()) {
                    throw new RuntimeException('Una raiz persistente contiene un enlace no permitido.');
                }
                if ($item->isFile() && ! isset($expected[$relative])) {
                    unlink($item->getPathname());
                } elseif ($item->isDir() && iterator_count(new \FilesystemIterator($item->getPathname())) === 0) {
                    rmdir($item->getPathname());
                }
            }
        }
    }
}
