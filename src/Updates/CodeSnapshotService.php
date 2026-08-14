<?php

namespace Waadby\OperationsAgent\Updates;

use RuntimeException;

final class CodeSnapshotService
{
    public function __construct(private readonly ReleaseCanonicalizer $canonicalizer, private readonly ReleasePathPolicy $paths, private readonly InstalledReleaseStore $installedRelease) {}

    /** @param list<array{path:string,sha256:string,size:int,operation:string}> $files
     * @return array{path:string,sha256:string,manifest:array<string,mixed>}
     */
    public function create(string $root, string $sessionId, array $files): array
    {
        $directory = rtrim((string) config('waadby_operations.updates.snapshot_path', storage_path('app/private/waadby-operations/snapshots')), '/\\').DIRECTORY_SEPARATOR.$sessionId;
        if (file_exists($directory)) {
            throw new RuntimeException('Ya existe un snapshot para la sesion de update.');
        }
        if (! mkdir($directory.DIRECTORY_SEPARATOR.'files', 0700, true) && ! is_dir($directory.DIRECTORY_SEPARATOR.'files')) {
            throw new RuntimeException('No se pudo crear el snapshot privado.');
        }
        $entries = [];
        foreach ($files as $file) {
            $relative = $this->paths->assertSafe($file['path']);
            $source = rtrim($root, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $exists = is_file($source);
            $entry = ['path' => $relative, 'existed' => $exists, 'sha256' => null, 'size' => 0];
            if ($exists) {
                if (is_link($source)) {
                    throw new RuntimeException('El destino de update contiene un symlink no permitido.');
                }
                $target = $directory.DIRECTORY_SEPARATOR.'files'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $this->directory(dirname($target));
                if (! copy($source, $target)) {
                    throw new RuntimeException('No se pudo copiar un original al snapshot.');
                }
                @chmod($target, 0600);
                $entry['sha256'] = hash_file('sha256', $target);
                $entry['size'] = filesize($target);
            }
            $entries[] = $entry;
        }
        $statePath = $this->installedRelease->path();
        $stateExists = is_file($statePath);
        $state = ['existed' => $stateExists, 'sha256' => null, 'size' => 0];
        if ($stateExists) {
            $stateTarget = $directory.DIRECTORY_SEPARATOR.'installed-release.json';
            if (! copy($statePath, $stateTarget)) {
                throw new RuntimeException('No se pudo copiar el estado instalado al snapshot.');
            }
            $state['sha256'] = hash_file('sha256', $stateTarget);
            $state['size'] = filesize($stateTarget);
            @chmod($stateTarget, 0600);
        }
        $manifest = ['snapshot_version' => 1, 'session_id' => $sessionId, 'files' => $entries, 'installed_release' => $state];
        $sha = hash('sha256', $this->canonicalizer->json($manifest));
        $json = json_encode([...$manifest, 'snapshot_sha256' => $sha], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        file_put_contents($directory.DIRECTORY_SEPARATOR.'snapshot.json', $json, LOCK_EX);
        @chmod($directory.DIRECTORY_SEPARATOR.'snapshot.json', 0600);
        $this->verify($directory, $sha);

        return ['path' => $directory, 'sha256' => $sha, 'manifest' => $manifest];
    }

    /** @return array<string, mixed> */
    public function verify(string $directory, string $expectedSha): array
    {
        try {
            $document = json_decode((string) file_get_contents($directory.DIRECTORY_SEPARATOR.'snapshot.json'), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new RuntimeException('El snapshot no contiene un manifest verificable.', 0, $exception);
        }
        if (! is_array($document) || ! is_string($document['snapshot_sha256'] ?? null)) {
            throw new RuntimeException('El snapshot no contiene SHA-256 propio.');
        }
        $stored = $document['snapshot_sha256'];
        unset($document['snapshot_sha256']);
        $actual = hash('sha256', $this->canonicalizer->json($document));
        if (! hash_equals($expectedSha, $stored) || ! hash_equals($expectedSha, $actual)) {
            throw new RuntimeException('El manifest del snapshot esta corrupto.');
        }
        foreach ($document['files'] ?? [] as $entry) {
            if (! is_array($entry) || ! ($entry['existed'] ?? false)) {
                continue;
            }
            $path = $directory.DIRECTORY_SEPARATOR.'files'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry['path']);
            if (! is_file($path) || filesize($path) !== $entry['size'] || ! hash_equals((string) $entry['sha256'], hash_file('sha256', $path))) {
                throw new RuntimeException('Un fichero del snapshot esta corrupto.');
            }
        }
        $state = $document['installed_release'] ?? [];
        if (($state['existed'] ?? false) === true) {
            $path = $directory.DIRECTORY_SEPARATOR.'installed-release.json';
            if (! is_file($path) || filesize($path) !== $state['size'] || ! hash_equals((string) $state['sha256'], hash_file('sha256', $path))) {
                throw new RuntimeException('El estado instalado del snapshot esta corrupto.');
            }
        }

        return $document;
    }

    public function restore(string $root, string $directory, string $expectedSha): void
    {
        $manifest = $this->verify($directory, $expectedSha);
        foreach ($manifest['files'] as $entry) {
            $relative = $this->paths->assertSafe((string) $entry['path']);
            $target = rtrim($root, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (($entry['existed'] ?? false) !== true) {
                if (is_file($target) && ! @unlink($target)) {
                    throw new RuntimeException('No se pudo retirar un fichero nuevo durante rollback.');
                }

                continue;
            }
            $source = $directory.DIRECTORY_SEPARATOR.'files'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $this->replace($source, $target, (string) $entry['sha256']);
        }
        $state = $manifest['installed_release'];
        $stateTarget = $this->installedRelease->path();
        if (($state['existed'] ?? false) === true) {
            $this->replace($directory.DIRECTORY_SEPARATOR.'installed-release.json', $stateTarget, (string) $state['sha256']);
        } elseif (is_file($stateTarget) && ! @unlink($stateTarget)) {
            throw new RuntimeException('No se pudo retirar el estado instalado nuevo durante rollback.');
        }
    }

    private function replace(string $source, string $target, string $sha): void
    {
        $this->directory(dirname($target));
        $temporary = $target.'.rollback-'.bin2hex(random_bytes(6));
        if (! copy($source, $temporary) || ! hash_equals($sha, hash_file('sha256', $temporary))) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo preparar un fichero de rollback verificado.');
        }
        if (is_file($target) && ! @unlink($target)) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo sustituir un fichero durante rollback.');
        }
        if (! @rename($temporary, $target) || ! hash_equals($sha, hash_file('sha256', $target))) {
            @unlink($temporary);
            throw new RuntimeException('El fichero restaurado no supera SHA-256.');
        }
    }

    private function directory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('No se pudo crear un directorio privado de rollback.');
        }
    }
}
