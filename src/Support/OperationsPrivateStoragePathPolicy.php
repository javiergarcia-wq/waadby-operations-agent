<?php

namespace Waadby\OperationsAgent\Support;

use RuntimeException;

final class OperationsPrivateStoragePathPolicy
{
    public function validateConfiguredPrivatePath(
        string $configuredPath,
        ?string $workingDirectory = null,
        ?string $publicDirectory = null,
    ): string {
        $absolute = $this->absoluteConfiguredPath($configuredPath, $workingDirectory ?? base_path());
        $public = $this->canonicalPublicDirectory($publicDirectory ?? public_path());
        $candidate = $this->resolveFromExistingAncestor($absolute);
        $this->assertOutsidePublic($candidate, $public);

        return $candidate;
    }

    public function prepareDirectory(
        string $configuredPath,
        ?string $workingDirectory = null,
        ?string $publicDirectory = null,
    ): string {
        $candidate = $this->validateConfiguredPrivatePath($configuredPath, $workingDirectory, $publicDirectory);
        $public = $this->canonicalPublicDirectory($publicDirectory ?? public_path());

        if (! is_dir($candidate) && ! mkdir($candidate, 0700, true) && ! is_dir($candidate)) {
            throw new RuntimeException('No se pudo crear el directorio privado de Operations.');
        }

        $canonical = $this->canonicalExistingDirectory($candidate);
        $this->assertOutsidePublic($canonical, $public);
        $this->applyPrivateDirectoryPermissions($canonical);

        return $canonical;
    }

    public function prepareFile(
        string $configuredPath,
        ?string $workingDirectory = null,
        ?string $publicDirectory = null,
    ): string {
        $absolute = $this->absoluteConfiguredPath($configuredPath, $workingDirectory ?? base_path());
        $basename = basename(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absolute));
        if ($basename === '' || $basename === '.' || $basename === '..') {
            throw new RuntimeException('La ruta privada debe identificar un fichero.');
        }

        $directory = $this->prepareDirectory(dirname($absolute), $workingDirectory, $publicDirectory);
        $path = $directory.DIRECTORY_SEPARATOR.$basename;
        $this->assertSafeExistingFile($path);

        return $path;
    }

    public function prepareChildDirectory(
        string $configuredRoot,
        string $child,
        ?string $workingDirectory = null,
        ?string $publicDirectory = null,
    ): string {
        $this->assertSafeChild($child);
        $root = $this->prepareDirectory($configuredRoot, $workingDirectory, $publicDirectory);
        $directory = $this->prepareDirectory($root.DIRECTORY_SEPARATOR.$child, $root, $publicDirectory);
        $this->assertContained($directory, $root);

        return $directory;
    }

    public function existingChildDirectory(
        string $configuredRoot,
        string $child,
        ?string $workingDirectory = null,
        ?string $publicDirectory = null,
    ): string {
        $this->assertSafeChild($child);
        $root = $this->prepareDirectory($configuredRoot, $workingDirectory, $publicDirectory);
        $directory = $root.DIRECTORY_SEPARATOR.$child;
        if (! is_dir($directory)) {
            throw new RuntimeException('El directorio privado solicitado no existe.');
        }
        $canonical = $this->canonicalExistingDirectory($directory);
        $this->assertContained($canonical, $root);
        $this->assertOutsidePublic($canonical, $this->canonicalPublicDirectory($publicDirectory ?? public_path()));

        return $canonical;
    }

    public function assertExistingDirectoryWithinRoot(
        string $configuredRoot,
        string $candidate,
        ?string $workingDirectory = null,
        ?string $publicDirectory = null,
    ): string {
        $root = $this->prepareDirectory($configuredRoot, $workingDirectory, $publicDirectory);
        $absolute = $this->absoluteConfiguredPath($candidate, $workingDirectory ?? base_path());
        $canonical = $this->canonicalExistingDirectory($absolute);
        $this->assertContained($canonical, $root);
        $this->assertOutsidePublic($canonical, $this->canonicalPublicDirectory($publicDirectory ?? public_path()));

        return $canonical;
    }

    public function assertFileWithinRoot(string $root, string $path, bool $required = false): string
    {
        $canonicalRoot = $this->canonicalExistingDirectory($root);
        $absolute = $this->absoluteConfiguredPath($path, $canonicalRoot);
        $this->assertContained($absolute, $canonicalRoot);
        if ($required && ! is_file($absolute)) {
            throw new RuntimeException('El fichero privado solicitado no existe.');
        }
        $this->assertSafeExistingFile($absolute);

        return $absolute;
    }

    public function protectFile(string $path): void
    {
        $this->assertSafeExistingFile($path);
        if (! file_exists($path)) {
            return;
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            if (! @chmod($path, 0600) || ((fileperms($path) ?: 0) & 0077) !== 0) {
                throw new RuntimeException('No se pudieron aplicar permisos privados al fichero de Operations.');
            }
        } else {
            @chmod($path, 0600);
        }
    }

    private function absoluteConfiguredPath(string $path, string $workingDirectory): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('La ruta privada de Operations no es valida.');
        }
        foreach (preg_split('~[\\\\/]+~', $path) ?: [] as $component) {
            if ($component === '.' || $component === '..') {
                throw new RuntimeException('La ruta privada no puede contener componentes relativos.');
            }
        }
        if (preg_match('~^[A-Za-z]:[^\\\\/]~', $path) === 1) {
            throw new RuntimeException('La ruta privada no puede ser relativa a una unidad de Windows.');
        }

        $native = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if ($this->isAbsolute($native)) {
            return $this->normalize($native);
        }

        return $this->normalize(rtrim($workingDirectory, '/\\').DIRECTORY_SEPARATOR.$native);
    }

    private function resolveFromExistingAncestor(string $path): string
    {
        $cursor = $path;
        $missing = [];
        while (! file_exists($cursor) && ! is_link($cursor)) {
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                throw new RuntimeException('No se pudo resolver un ancestro de la ruta privada.');
            }
            array_unshift($missing, basename($cursor));
            $cursor = $parent;
        }

        $canonical = $this->canonicalExistingDirectory($cursor);

        return $this->normalize($canonical.($missing === [] ? '' : DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $missing)));
    }

    private function canonicalExistingDirectory(string $directory): string
    {
        clearstatcache(true, $directory);
        if (is_link($directory)) {
            throw new RuntimeException('La ruta privada contiene un enlace simbolico no permitido.');
        }
        $stat = @lstat($directory);
        $real = realpath($directory);
        if ($stat === false || $real === false || ! is_dir($real) || ! $this->samePath($real, $directory)) {
            throw new RuntimeException('La ruta privada contiene un enlace, junction o reparse point no permitido.');
        }

        return $this->normalize($real);
    }

    private function canonicalPublicDirectory(string $publicDirectory): string
    {
        $public = realpath($publicDirectory);
        if ($public === false || ! is_dir($public)) {
            throw new RuntimeException('No se pudo canonicalizar public/.');
        }

        return $this->normalize($public);
    }

    private function assertSafeExistingFile(string $path): void
    {
        clearstatcache(true, $path);
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        if (is_link($path)) {
            throw new RuntimeException('El fichero privado no puede ser un enlace simbolico.');
        }
        $stat = @lstat($path);
        $real = realpath($path);
        if ($stat === false || $real === false || ! is_file($real) || ! $this->samePath($real, $path)) {
            throw new RuntimeException('El fichero privado no es un fichero regular canonical.');
        }
        if (($stat['nlink'] ?? 1) > 1) {
            throw new RuntimeException('El fichero privado no puede tener enlaces adicionales.');
        }
    }

    private function assertSafeChild(string $child): void
    {
        if ($child === '' || str_contains($child, "\0") || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $child) !== 1 || $child === '.' || $child === '..') {
            throw new RuntimeException('El identificador del directorio privado no es seguro.');
        }
    }

    private function assertOutsidePublic(string $candidate, string $public): void
    {
        if ($this->contains($candidate, $public)) {
            throw new RuntimeException('El almacenamiento interno de Operations debe quedar fuera de public/.');
        }
    }

    private function assertContained(string $candidate, string $root): void
    {
        if (! $this->contains($candidate, $root)) {
            throw new RuntimeException('La ruta privada escapa de su root canonicalizado.');
        }
    }

    private function applyPrivateDirectoryPermissions(string $directory): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            if (! @chmod($directory, 0700) || ((fileperms($directory) ?: 0) & 0077) !== 0) {
                throw new RuntimeException('No se pudieron aplicar permisos privados al directorio de Operations.');
            }
        } else {
            @chmod($directory, 0700);
        }
    }

    private function isAbsolute(string $path): bool
    {
        return preg_match('~^(?:[A-Za-z]:'.preg_quote(DIRECTORY_SEPARATOR, '~').'|'.preg_quote(DIRECTORY_SEPARATOR, '~').')~', $path) === 1;
    }

    private function normalize(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        return rtrim($path, DIRECTORY_SEPARATOR);
    }

    private function contains(string $candidate, string $root): bool
    {
        $candidate = rtrim($this->comparable($candidate), '/');
        $root = rtrim($this->comparable($root), '/');

        return $candidate === $root || str_starts_with($candidate.'/', $root.'/');
    }

    private function samePath(string $left, string $right): bool
    {
        return rtrim($this->comparable($left), '/') === rtrim($this->comparable($right), '/');
    }

    private function comparable(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}
