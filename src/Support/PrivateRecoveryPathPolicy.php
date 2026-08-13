<?php

namespace Waadby\OperationsAgent\Support;

use InvalidArgumentException;

final class PrivateRecoveryPathPolicy
{
    public function resolve(
        string $configuredOutput,
        string $workingDirectory,
        string $publicDirectory,
        bool $force,
    ): string {
        if ($configuredOutput === '' || str_contains($configuredOutput, "\0")) {
            throw new InvalidArgumentException('El output de recuperación no es una ruta válida.');
        }

        $this->rejectRelativeComponents($configuredOutput);
        $absolute = $this->absolute($configuredOutput, $workingDirectory);
        $basename = basename(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absolute));
        if ($basename === '' || $basename === '.' || $basename === '..') {
            throw new InvalidArgumentException('El output debe identificar un fichero privado.');
        }

        $directory = $this->canonicalDirectory(dirname($absolute));
        $public = realpath($publicDirectory);
        if ($public === false || ! is_dir($public)) {
            throw new InvalidArgumentException('No se pudo resolver de forma segura el directorio public/.');
        }
        if ($this->contains($this->normalize($directory), $this->normalize($public))) {
            throw new InvalidArgumentException('El output descifrado no puede escribirse dentro de public/.');
        }

        $output = $directory.DIRECTORY_SEPARATOR.$basename;
        $this->assertExistingOutputSafe($output, $force);

        return $output;
    }

    public function assertExistingOutputSafe(string $output, bool $force): void
    {
        clearstatcache(true, $output);
        if (! $this->pathExists($output)) {
            return;
        }
        if (is_link($output)) {
            throw new InvalidArgumentException('El output existente no puede ser un enlace simbólico.');
        }
        $stat = @lstat($output);
        if (! is_file($output) || $stat === false || (($stat['mode'] & 0170000) !== 0100000)) {
            throw new InvalidArgumentException('El output existente debe ser un fichero regular seguro.');
        }
        if (($stat['nlink'] ?? 1) > 1) {
            throw new InvalidArgumentException('El output existente no puede tener enlaces adicionales.');
        }
        if (! $force) {
            throw new InvalidArgumentException('El output ya existe; use --force únicamente tras verificar el destino.');
        }
    }

    public function pathExists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    private function rejectRelativeComponents(string $path): void
    {
        foreach (preg_split('~[\\\\/]+~', $path) ?: [] as $component) {
            if ($component === '.' || $component === '..') {
                throw new InvalidArgumentException('El output no puede contener componentes relativos "." o "..".');
            }
        }
        if (preg_match('~^[A-Za-z]:[^\\\\/]~', $path) === 1) {
            throw new InvalidArgumentException('El output no puede usar una ruta relativa a una unidad de Windows.');
        }
    }

    private function absolute(string $path, string $workingDirectory): string
    {
        if (preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $path) === 1) {
            return $this->normalize($path);
        }

        return $this->normalize($workingDirectory.DIRECTORY_SEPARATOR.$path);
    }

    private function canonicalDirectory(string $directory): string
    {
        $cursor = $this->normalize($directory);
        $missing = [];

        while (! file_exists($cursor) && ! is_link($cursor)) {
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                throw new InvalidArgumentException('No se pudo resolver el directorio privado de salida.');
            }
            array_unshift($missing, basename($cursor));
            $cursor = $parent;
        }

        $real = realpath($cursor);
        if ($real === false || ! is_dir($real)) {
            throw new InvalidArgumentException('El directorio de salida contiene un enlace inválido o no es un directorio.');
        }

        return $this->normalize($real.(count($missing) > 0 ? DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $missing) : ''));
    }

    private function normalize(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $prefix = '';

        if (preg_match('~^[A-Za-z]:'.preg_quote(DIRECTORY_SEPARATOR, '~').'~', $path) === 1) {
            $prefix = strtoupper(substr($path, 0, 2)).DIRECTORY_SEPARATOR;
            $path = substr($path, 3);
        } elseif (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $prefix = DIRECTORY_SEPARATOR;
            $path = ltrim($path, DIRECTORY_SEPARATOR);
        }

        $components = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }
            if ($component === '..') {
                if ($components === []) {
                    throw new InvalidArgumentException('La ruta de output escapa de su raíz.');
                }
                array_pop($components);

                continue;
            }
            $components[] = $component;
        }

        return $prefix.implode(DIRECTORY_SEPARATOR, $components);
    }

    private function contains(string $candidate, string $root): bool
    {
        $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidate = strtolower($candidate);
            $root = strtolower($root);
        }

        return $candidate === $root || str_starts_with($candidate.'/', $root.'/');
    }
}
