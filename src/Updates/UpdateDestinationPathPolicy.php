<?php

namespace Waadby\OperationsAgent\Updates;

use RuntimeException;

final class UpdateDestinationPathPolicy
{
    public function resolveFile(string $root, string $relative): string
    {
        $canonicalRoot = realpath($root);
        if ($canonicalRoot === false || ! is_dir($canonicalRoot)) {
            throw new RuntimeException('No se pudo canonicalizar el root del update.');
        }

        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_contains($relative, "\0") || str_starts_with($relative, '/') || preg_match('~^[A-Za-z]:~', $relative) === 1) {
            throw new RuntimeException('El destino del update no es una ruta relativa segura.');
        }
        $components = explode('/', $relative);
        if (in_array('', $components, true) || in_array('.', $components, true) || in_array('..', $components, true)) {
            throw new RuntimeException('El destino del update contiene componentes relativos no permitidos.');
        }

        $cursor = rtrim($canonicalRoot, '/\\');
        foreach ($components as $index => $component) {
            $cursor .= DIRECTORY_SEPARATOR.$component;
            clearstatcache(true, $cursor);
            if (is_link($cursor)) {
                throw new RuntimeException('El destino del update contiene un enlace simbolico no permitido.');
            }
            if (! file_exists($cursor)) {
                continue;
            }
            $stat = @lstat($cursor);
            if ($stat === false) {
                throw new RuntimeException('No se pudo inspeccionar de forma segura un componente del destino.');
            }
            $resolved = realpath($cursor);
            if ($resolved === false || ! $this->contains($resolved, $canonicalRoot) || ! $this->samePath($resolved, $cursor)) {
                throw new RuntimeException('El destino del update contiene un enlace, junction o escape de root no permitido.');
            }
            $last = $index === array_key_last($components);
            if (! $last && ! is_dir($cursor)) {
                throw new RuntimeException('Un ancestro del destino del update no es un directorio.');
            }
            if ($last && ! is_file($cursor)) {
                throw new RuntimeException('El destino existente del update no es un fichero regular.');
            }
        }

        if (! $this->contains($cursor, $canonicalRoot)) {
            throw new RuntimeException('El destino del update escapa del root canonicalizado.');
        }

        return $cursor;
    }

    private function contains(string $candidate, string $root): bool
    {
        $candidate = $this->comparable($candidate);
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
