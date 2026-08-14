<?php

namespace Waadby\OperationsAgent\Support;

use RuntimeException;

final class ReleaseBuildOutputPathPolicy
{
    public function prepare(string $configuredOutput, string $workingDirectory, string $publicDirectory): string
    {
        if ($configuredOutput === '' || str_contains($configuredOutput, "\0")) {
            throw new RuntimeException('El output del release no es una ruta valida.');
        }
        foreach (preg_split('~[\\\\/]+~', $configuredOutput) ?: [] as $component) {
            if ($component === '.' || $component === '..') {
                throw new RuntimeException('El output del release no puede contener componentes relativos.');
            }
        }
        if (preg_match('~^[A-Za-z]:[^\\\\/]~', $configuredOutput) === 1) {
            throw new RuntimeException('El output no puede ser relativo a una unidad de Windows.');
        }

        $absolute = $this->absolute($configuredOutput, $workingDirectory);
        $cursor = $absolute;
        $missing = [];
        while (! file_exists($cursor) && ! is_link($cursor)) {
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                throw new RuntimeException('No se pudo resolver el output privado del release.');
            }
            array_unshift($missing, basename($cursor));
            $cursor = $parent;
        }
        if (is_link($cursor)) {
            throw new RuntimeException('El output del release contiene un enlace simbolico no permitido.');
        }
        if (@lstat($cursor) === false) {
            throw new RuntimeException('No se pudo inspeccionar de forma segura el output del release.');
        }
        $resolved = realpath($cursor);
        if ($resolved === false || ! is_dir($resolved) || ! $this->samePath($resolved, $cursor)) {
            throw new RuntimeException('El output del release contiene un enlace o junction no permitido.');
        }
        $candidate = rtrim($resolved, '/\\').($missing === [] ? '' : DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $missing));
        $public = realpath($publicDirectory);
        if ($public === false || ! is_dir($public) || $this->contains($candidate, $public)) {
            throw new RuntimeException('El output del release debe quedar fuera de public/.');
        }
        if (! is_dir($candidate) && ! mkdir($candidate, 0700, true) && ! is_dir($candidate)) {
            throw new RuntimeException('No se pudo crear el directorio de salida privado.');
        }
        clearstatcache(true, $candidate);
        if (is_link($candidate)) {
            throw new RuntimeException('El output creado no puede ser un enlace simbolico.');
        }
        if (@lstat($candidate) === false) {
            throw new RuntimeException('No se pudo verificar el tipo fisico del output creado.');
        }
        $canonical = realpath($candidate);
        if ($canonical === false || ! is_dir($canonical) || ! $this->samePath($canonical, $candidate) || $this->contains($canonical, $public)) {
            throw new RuntimeException('El output creado no conserva un destino privado canonical.');
        }
        if (! @chmod($canonical, 0700)) {
            throw new RuntimeException('No se pudieron aplicar permisos privados al output del release.');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && ((fileperms($canonical) ?: 0) & 0077) !== 0) {
            throw new RuntimeException('El output del release conserva permisos de grupo u otros.');
        }

        return $canonical;
    }

    private function absolute(string $path, string $workingDirectory): string
    {
        if (preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $path) === 1) {
            return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }

        return rtrim($workingDirectory, '/\\').DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
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
