<?php

namespace Waadby\OperationsAgent\Vault;

use RuntimeException;

final class VaultEnvelopeCipher
{
    public const FORMAT = 'WAADBY-VAULT-V1';

    private const MAGIC = "WAADBY-VAULT-V1\n";

    /** @param resource $source @param resource $destination @param array<string, mixed> $metadata
     * @return array{source_sha256: string, source_size: int, vault_cipher_sha256: string, vault_size: int, header: array<string, mixed>}
     */
    public function encrypt($source, $destination, array $metadata, string $configuredKey, string $keyId, ?int $chunkBytes = null): array
    {
        $key = $this->key($configuredKey);
        $chunkBytes ??= (int) config('waadby_operations.vault.chunk_bytes', 1048576);
        if (! is_resource($source) || ! is_resource($destination) || $chunkBytes < 65536 || $keyId === '') {
            throw new RuntimeException('La configuración de cifrado Vault no es válida.');
        }
        $sourceStat = fstat($source);
        if (! is_array($sourceStat) || ! isset($sourceStat['size']) || $sourceStat['size'] < 0) {
            throw new RuntimeException('No se pudo determinar el tamaño del backup origen.');
        }
        $sourceHash = hash_init('sha256');
        $sourceSize = 0;
        $header = [
            'format_version' => 1,
            'source_backup_id' => (string) ($metadata['source_backup_id'] ?? ''),
            'application_code' => (string) ($metadata['application_code'] ?? ''),
            'application_version' => (string) ($metadata['application_version'] ?? ''),
            'source_sha256' => (string) ($metadata['source_sha256'] ?? ''),
            'source_size' => (int) ($metadata['source_size'] ?? $sourceStat['size']),
            'key_id' => $keyId,
            'created_at' => (string) ($metadata['created_at'] ?? now()->utc()->toIso8601String()),
            'cipher' => 'secretstream_xchacha20poly1305',
        ];
        if (! preg_match('/^[a-f0-9]{64}$/', $header['source_sha256']) || $header['source_size'] < 0) {
            throw new RuntimeException('La metadata del backup origen no es válida.');
        }
        $json = json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 16384) {
            throw new RuntimeException('El header Vault supera el límite permitido.');
        }
        [$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $cipherHash = hash_init('sha256');
        $vaultSize = 0;
        $prefix = self::MAGIC.pack('N', strlen($json)).$json.$streamHeader;
        $this->write($destination, $prefix, $cipherHash, $vaultSize);
        $associatedData = hash('sha256', self::MAGIC.$json, true);

        while (! feof($source)) {
            $plain = fread($source, $chunkBytes);
            if ($plain === false) {
                throw new RuntimeException('No se pudo leer el backup origen.');
            }
            if ($plain === '' && feof($source)) {
                break;
            }
            hash_update($sourceHash, $plain);
            $sourceSize += strlen($plain);
            $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                $plain,
                $associatedData,
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
            );
            $this->write($destination, pack('N', strlen($ciphertext)).$ciphertext, $cipherHash, $vaultSize);
        }
        $final = sodium_crypto_secretstream_xchacha20poly1305_push(
            $state,
            '',
            $associatedData,
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
        );
        $this->write($destination, pack('N', strlen($final)).$final, $cipherHash, $vaultSize);
        $actualSourceHash = hash_final($sourceHash);
        if ($sourceSize !== $header['source_size'] || ! hash_equals($header['source_sha256'], $actualSourceHash)) {
            throw new RuntimeException('El backup origen cambió durante el cifrado Vault.');
        }

        return ['source_sha256' => $actualSourceHash, 'source_size' => $sourceSize, 'vault_cipher_sha256' => hash_final($cipherHash), 'vault_size' => $vaultSize, 'header' => $header];
    }

    /** @param resource $source @param resource|null $destination
     * @return array{source_sha256: string, source_size: int, vault_cipher_sha256: string, vault_size: int, header: array<string, mixed>}
     */
    public function decrypt($source, $destination, string $configuredKey): array
    {
        $key = $this->key($configuredKey);
        if (! is_resource($source) || ($destination !== null && ! is_resource($destination))) {
            throw new RuntimeException('El stream Vault no es válido.');
        }
        $cipherHash = hash_init('sha256');
        $vaultSize = 0;
        $magic = $this->readExact($source, strlen(self::MAGIC), $cipherHash, $vaultSize);
        if (! hash_equals(self::MAGIC, $magic)) {
            throw new RuntimeException('El formato Vault no es válido.');
        }
        $headerLength = unpack('Nlength', $this->readExact($source, 4, $cipherHash, $vaultSize))['length'];
        if ($headerLength < 2 || $headerLength > 16384) {
            throw new RuntimeException('El header Vault no es válido.');
        }
        $json = $this->readExact($source, $headerLength, $cipherHash, $vaultSize);
        $header = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($header) || ($header['format_version'] ?? null) !== 1 || ($header['cipher'] ?? null) !== 'secretstream_xchacha20poly1305') {
            throw new RuntimeException('La versión del envelope Vault no es compatible.');
        }
        $streamHeader = $this->readExact($source, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES, $cipherHash, $vaultSize);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($streamHeader, $key);
        $associatedData = hash('sha256', self::MAGIC.$json, true);
        $plainHash = hash_init('sha256');
        $plainSize = 0;
        $sawFinal = false;
        while (! feof($source)) {
            $lengthBytes = fread($source, 4);
            if ($lengthBytes === false || $lengthBytes === '') {
                break;
            }
            if (strlen($lengthBytes) !== 4) {
                throw new RuntimeException('El envelope Vault está truncado.');
            }
            hash_update($cipherHash, $lengthBytes);
            $vaultSize += 4;
            $length = unpack('Nlength', $lengthBytes)['length'];
            if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > (int) config('waadby_operations.vault.chunk_bytes', 1048576) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                throw new RuntimeException('Un chunk Vault no es válido.');
            }
            $ciphertext = $this->readExact($source, $length, $cipherHash, $vaultSize);
            $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext, $associatedData);
            if ($pulled === false) {
                throw new RuntimeException('La autenticación del envelope Vault falló.');
            }
            [$plain, $tag] = $pulled;
            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                if ($plain !== '' || ! feof($source)) {
                    $extra = fread($source, 1);
                    if ($extra !== false && $extra !== '') {
                        throw new RuntimeException('Existen datos después del TAG_FINAL de Vault.');
                    }
                }
                $sawFinal = true;
                break;
            }
            if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE || $sawFinal) {
                throw new RuntimeException('La secuencia de tags Vault no es válida.');
            }
            hash_update($plainHash, $plain);
            $plainSize += strlen($plain);
            if ($destination !== null && fwrite($destination, $plain) !== strlen($plain)) {
                throw new RuntimeException('No se pudo escribir el backup descifrado.');
            }
        }
        if (! $sawFinal) {
            throw new RuntimeException('El envelope Vault no contiene TAG_FINAL.');
        }
        $sourceHash = hash_final($plainHash);
        if ((int) ($header['source_size'] ?? -1) !== $plainSize || ! is_string($header['source_sha256'] ?? null) || ! hash_equals($header['source_sha256'], $sourceHash)) {
            throw new RuntimeException('La integridad del backup contenido en Vault no coincide.');
        }

        return ['source_sha256' => $sourceHash, 'source_size' => $plainSize, 'vault_cipher_sha256' => hash_final($cipherHash), 'vault_size' => $vaultSize, 'header' => $header];
    }

    private function key(string $value): string
    {
        $value = trim($value);
        $decoded = str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : (ctype_xdigit($value) ? hex2bin($value) : false);
        if (! is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('WAADBY_OPERATIONS_VAULT_KEY debe contener exactamente 32 bytes externos.');
        }

        return $decoded;
    }

    /** @param resource $destination @param resource $hash */
    private function write($destination, string $bytes, $hash, int &$size): void
    {
        if (fwrite($destination, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('No se pudo escribir el envelope Vault.');
        }
        hash_update($hash, $bytes);
        $size += strlen($bytes);
    }

    /** @param resource $source @param resource $hash */
    private function readExact($source, int $length, $hash, int &$size): string
    {
        $bytes = '';
        while (strlen($bytes) < $length && ! feof($source)) {
            $part = fread($source, $length - strlen($bytes));
            if ($part === false) {
                throw new RuntimeException('No se pudo leer el envelope Vault.');
            }
            $bytes .= $part;
        }
        if (strlen($bytes) !== $length) {
            throw new RuntimeException('El envelope Vault está truncado.');
        }
        hash_update($hash, $bytes);
        $size += strlen($bytes);

        return $bytes;
    }
}
