<?php

namespace Waadby\OperationsAgent\Restores;

use RuntimeException;

final class RestoreApplier
{
    public function __construct(private readonly DatabaseRestoreService $databases, private readonly StorageRestoreService $storage) {}

    /** @param array<string, mixed> $manifest */
    public function validate(string $stage, array $manifest): void
    {
        if (($manifest['database']['included'] ?? false) === true) {
            $dump = $stage.DIRECTORY_SEPARATOR.'database.sql';
            if (! is_file($dump)) {
                throw new RuntimeException('El staging restore no contiene database.sql.');
            }
            $this->databases->validate($dump, (string) $manifest['database']['driver']);
        }
    }

    /** @param array<string, mixed> $manifest */
    public function apply(string $stage, array $manifest): void
    {
        if (($manifest['database']['included'] ?? false) === true) {
            $this->databases->apply($stage.DIRECTORY_SEPARATOR.'database.sql', (string) $manifest['database']['driver']);
        }
        if (($manifest['storage']['included'] ?? false) === true) {
            $this->storage->apply($stage, array_values((array) ($manifest['storage']['files'] ?? [])));
        }
    }
}
