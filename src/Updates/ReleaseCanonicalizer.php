<?php

namespace Waadby\OperationsAgent\Updates;

final class ReleaseCanonicalizer
{
    /** @param array<string, mixed> $manifest */
    public function payload(array $manifest): string
    {
        return $this->json([
            'manifest' => $manifest,
            'package_sha256' => strtolower((string) ($manifest['package_sha256'] ?? '')),
            'files_manifest_sha256' => strtolower((string) ($manifest['package']['files_manifest_sha256'] ?? '')),
        ]);
    }

    public function json(mixed $value): string
    {
        return json_encode($this->sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sort($item), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->sort($item), $value);
    }
}
