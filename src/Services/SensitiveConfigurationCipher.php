<?php

namespace Waadby\OperationsAgent\Services;

use RuntimeException;

class SensitiveConfigurationCipher
{
    /** @param array<string, scalar|null> $configuration */
    public function encrypt(array $configuration, string $configuredKey): string
    {
        if (! extension_loaded('sodium')) {
            throw new RuntimeException('La extension Sodium es obligatoria para cifrar backups de desastre.');
        }

        $key = $this->normalizeKey($configuredKey);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        sodium_memzero($key);

        return json_encode([
            'format_version' => 1,
            'cipher' => 'sodium_secretbox',
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function hasValidKey(?string $configuredKey): bool
    {
        try {
            $this->normalizeKey((string) $configuredKey);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function normalizeKey(string $configuredKey): string
    {
        if ($configuredKey === '') {
            throw new RuntimeException('No se puede crear un backup de desastre sin una clave de backup configurada.');
        }

        if (str_starts_with($configuredKey, 'base64:')) {
            $decoded = base64_decode(substr($configuredKey, 7), true);
        } elseif (preg_match('/^[a-f0-9]{64}$/i', $configuredKey)) {
            $decoded = hex2bin($configuredKey);
        } else {
            $decoded = $configuredKey;
        }

        if (! is_string($decoded) || strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('No se puede crear un backup de desastre sin una clave de backup configurada.');
        }

        return sodium_crypto_generichash($decoded, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
