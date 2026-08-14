<?php

namespace Waadby\OperationsAgent\Updates;

use RuntimeException;

final class ReleaseSignatureService
{
    public function __construct(private readonly ReleaseCanonicalizer $canonicalizer) {}

    /** @param array<string, mixed> $manifest
     * @return array{signature_version:int,key_id:string,algorithm:string,signature:string}
     */
    public function sign(array $manifest, string $privateKey, string $keyId): array
    {
        $this->assertSodium();
        $key = $this->decode($privateKey, 'private');
        if (strlen($key) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            $key = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($key));
        }
        if (strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES || trim($keyId) === '') {
            throw new RuntimeException('La clave Ed25519 privada o key_id no son validos.');
        }

        return [
            'signature_version' => 1,
            'key_id' => trim($keyId),
            'algorithm' => 'Ed25519',
            'signature' => base64_encode(sodium_crypto_sign_detached($this->canonicalizer->payload($manifest), $key)),
        ];
    }

    /** @param array<string, mixed> $manifest @param array<string, mixed> $signature */
    public function verify(array $manifest, array $signature): void
    {
        $this->assertSodium();
        if (($signature['signature_version'] ?? null) !== 1 || ($signature['algorithm'] ?? null) !== 'Ed25519') {
            throw new RuntimeException('El formato de firma del release no esta soportado.');
        }
        $keyId = $signature['key_id'] ?? null;
        $encodedSignature = $signature['signature'] ?? null;
        if (! is_string($keyId) || ! is_string($encodedSignature)) {
            throw new RuntimeException('El release no contiene una firma valida.');
        }
        $trusted = $this->trustedKeys();
        if (! isset($trusted[$keyId]) || ! is_string($trusted[$keyId])) {
            throw new RuntimeException('La firma usa una key_id no confiable.');
        }
        $public = $this->decode($trusted[$keyId], 'public');
        $rawSignature = base64_decode($encodedSignature, true);
        if (strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $rawSignature === false || strlen($rawSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new RuntimeException('La firma Ed25519 no tiene un formato valido.');
        }
        if (! sodium_crypto_sign_verify_detached($rawSignature, $this->canonicalizer->payload($manifest), $public)) {
            throw new RuntimeException('La firma del release no supera la verificacion independiente.');
        }
    }

    /** @return array<string, string> */
    public function trustedKeys(): array
    {
        $configured = config('waadby_operations.updates.trusted_keys', '{}');
        if (is_array($configured)) {
            return $configured;
        }
        if (! is_string($configured) || trim($configured) === '') {
            return [];
        }
        try {
            $value = json_decode($configured, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('WAADBY_OPERATIONS_RELEASE_TRUSTED_KEYS no contiene JSON valido.');
        }

        return is_array($value) ? array_filter($value, 'is_string') : [];
    }

    private function decode(string $value, string $kind): string
    {
        $value = trim($value);
        if ($value !== '' && is_file($value)) {
            $value = trim((string) file_get_contents($value));
        }
        if (str_starts_with($value, 'base64:')) {
            $value = substr($value, 7);
        }
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new RuntimeException("La clave Ed25519 {$kind} no esta codificada en base64.");
        }

        return $decoded;
    }

    private function assertSodium(): void
    {
        if (! extension_loaded('sodium')) {
            throw new RuntimeException('La extension libsodium es obligatoria para releases firmados.');
        }
    }
}
