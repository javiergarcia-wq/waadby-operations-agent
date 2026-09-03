<?php

namespace Tests\Support;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Jose\Component\Core\JWKSet;
use Jose\Component\KeyManagement\JWKFactory;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use RuntimeException;
use Waadby\OperationsAgent\Remote\EnrollmentStore;

trait SignsOperationsRequests
{
    /** @var array{private: string, public: string, kid: string}|null */
    private static ?array $operationsSigningMaterial = null;

    /** @param array<string, mixed> $overrides */
    protected function signedRequest(
        string $operation,
        string $method,
        string $path,
        string $body = '',
        string $correlation = '',
        string $idempotency = '',
        array $overrides = [],
    ): string {
        $material = self::operationsSigningMaterial();
        $now = time();
        $installationId = (string) config('tests.installation_id');
        $configuration = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($material['private']),
            InMemory::plainText($material['public']),
        );
        $claims = array_replace([
            'iss' => 'http://127.0.0.1',
            'aud' => 'urn:waadby:operations:installation:'.$installationId,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 60,
            'jti' => (string) Str::uuid(),
            'method' => strtoupper($method),
            'path' => $path,
            'body_sha256' => hash('sha256', $body),
            'correlation_id' => $correlation,
            'idempotency_key' => $idempotency,
            'range' => '',
            'chunk_offset' => '',
            'operation' => $operation,
        ], $overrides);
        $builder = $configuration->builder()->withHeader('kid', $material['kid']);
        foreach ($claims as $name => $value) {
            $builder = match ($name) {
                'iss' => $builder->issuedBy($value),
                'aud' => $builder->permittedFor($value),
                'iat' => $builder->issuedAt((new DateTimeImmutable)->setTimestamp((int) $value)),
                'nbf' => $builder->canOnlyBeUsedAfter((new DateTimeImmutable)->setTimestamp((int) $value)),
                'exp' => $builder->expiresAt((new DateTimeImmutable)->setTimestamp((int) $value)),
                'jti' => $builder->identifiedBy($value),
                default => $builder->withClaim($name, $value),
            };
        }

        return $builder->getToken($configuration->signer(), $configuration->signingKey())->toString();
    }

    protected function enrollTestAgent(?string $application = null, ?string $environment = null, bool $revoked = false): void
    {
        $identity = [
            'installation_id' => (string) config('tests.installation_id'),
            'application_code' => $application ?? (string) config('waadby_operations.application.code'),
            'environment' => $environment ?? (string) config('waadby_operations.application.environment'),
            'access_origin' => 'http://127.0.0.1',
            'jwks_uri' => 'http://127.0.0.1/.well-known/waadby-operations-jwks.json',
            'jwks' => $this->operationsJwks(),
            'protocol_version' => '1',
        ];
        if ($revoked) {
            $identity['revoked_at'] = now()->toIso8601String();
        }
        app(EnrollmentStore::class)->put($identity);
    }

    /** @return array{keys: list<array<string, mixed>>} */
    protected function operationsJwks(): array
    {
        $material = self::operationsSigningMaterial();
        $set = (new JWKSet([
            JWKFactory::createFromKey($material['public'], additional_values: [
                'kid' => $material['kid'], 'use' => 'sig', 'alg' => 'RS256',
            ])->toPublic(),
        ]))->jsonSerialize();

        return json_decode(json_encode($set, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    }

    /** @return array{private: string, public: string, kid: string} */
    private static function operationsSigningMaterial(): array
    {
        if (self::$operationsSigningMaterial === null) {
            $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
            $opensslConfig = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
            if (is_file($opensslConfig)) {
                $options['config'] = $opensslConfig;
            }
            $key = openssl_pkey_new($options);
            if ($key === false || ! openssl_pkey_export($key, $private, null, $options)) {
                throw new RuntimeException('Unable to create test signing key.');
            }
            $details = openssl_pkey_get_details($key);
            if (! is_array($details) || ! is_string($details['key'] ?? null)) {
                throw new RuntimeException('Unable to export test signing key.');
            }
            $kid = (string) JWKFactory::createFromKey($details['key'])->thumbprint('sha256');
            self::$operationsSigningMaterial = ['private' => $private, 'public' => $details['key'], 'kid' => $kid];
        }

        return self::$operationsSigningMaterial;
    }
}
