<?php

namespace Waadby\OperationsAgent\Remote;

use DateTimeImmutable;
use Jose\Component\Core\JWK;
use Jose\Component\Core\Util\RSAKey;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use RuntimeException;

final class JwkTokenVerifier
{
    /** @param array{keys?: list<array<string, mixed>>} $jwks
     * @return array<string, mixed>
     */
    public function verify(string $compact, array $jwks): array
    {
        try {
            $probe = Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText('unused'), InMemory::plainText('unused'))->parser()->parse($compact);
            $kid = (string) $probe->headers()->get('kid', '');
            $algorithm = (string) $probe->headers()->get('alg', '');
            $candidate = collect($jwks['keys'] ?? [])->first(fn (array $key): bool => hash_equals((string) ($key['kid'] ?? ''), $kid));
            if ($kid === '' || $algorithm !== 'RS256' || ! is_array($candidate) || ($candidate['kty'] ?? null) !== 'RSA' || ($candidate['use'] ?? 'sig') !== 'sig') {
                throw new RuntimeException('La clave de firma remota no es válida.');
            }
            $pem = RSAKey::createFromJWK(new JWK($candidate))->toPEM();
            $configuration = Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText($pem), InMemory::plainText($pem));
            $token = $configuration->parser()->parse($compact);
            if (! $configuration->validator()->validate($token, new SignedWith($configuration->signer(), $configuration->verificationKey()))) {
                throw new RuntimeException('La firma remota no es válida.');
            }

            return $token->claims()->all();
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new RuntimeException('El token remoto no se pudo validar.', previous: $exception);
        }
    }

    public static function timestamp(mixed $value): int
    {
        return $value instanceof DateTimeImmutable ? $value->getTimestamp() : (int) $value;
    }

    /** @param array<string, mixed> $claims */
    public function assertTemporalClaims(array $claims, int $maximumTtlSeconds, int $clockSkewSeconds, ?int $now = null): void
    {
        foreach (['iat', 'nbf', 'exp'] as $claim) {
            if (! isset($claims[$claim]) || (! is_int($claims[$claim]) && ! $claims[$claim] instanceof DateTimeImmutable)) {
                throw new RuntimeException('temporal_claims_invalid');
            }
        }

        $now ??= time();
        $iat = self::timestamp($claims['iat']);
        $nbf = self::timestamp($claims['nbf']);
        $exp = self::timestamp($claims['exp']);
        if ($iat > $now + $clockSkewSeconds) {
            throw new RuntimeException('issued_in_future');
        }
        if ($nbf > $now + $clockSkewSeconds) {
            throw new RuntimeException('not_yet_valid');
        }
        if ($exp < $now - $clockSkewSeconds) {
            throw new RuntimeException('token_expired');
        }
        if ($exp <= $iat || $exp - $iat > $maximumTtlSeconds) {
            throw new RuntimeException('token_ttl_invalid');
        }
    }
}
