<?php

namespace Waadby\OperationsAgent\Remote;

use Illuminate\Http\Client\Factory;
use RuntimeException;

final class EnrollmentClient
{
    public function __construct(
        private readonly Factory $http,
        private readonly EnrollmentStore $store,
        private readonly JwkTokenVerifier $verifier,
    ) {}

    /** @return array<string, mixed> */
    public function enroll(string $accessUrl, string $token): array
    {
        $origin = $this->origin($accessUrl);
        $response = $this->http->baseUrl($origin)->acceptJson()->asJson()->withOptions([
            'allow_redirects' => false, 'verify' => ! $this->localHttpAllowed($origin),
        ])->timeout(10)->post('/api/v1/operations/enrollments/claim', ['token' => $token]);
        if (! $response->successful()) {
            throw new RuntimeException('ACCESS rechazó el enrollment.');
        }
        $payload = $response->json();
        if (! is_array($payload) || ! is_string($payload['document'] ?? null) || ! is_string($payload['jwks_uri'] ?? null)) {
            throw new RuntimeException('ACCESS devolvió un enrollment incompleto.');
        }
        $jwksOrigin = $this->origin($payload['jwks_uri']);
        if (! hash_equals($origin, $jwksOrigin)) {
            throw new RuntimeException('El JWKS de enrollment pertenece a un origen distinto.');
        }
        $jwksResponse = $this->http->acceptJson()->withOptions(['allow_redirects' => false, 'verify' => ! $this->localHttpAllowed($origin)])->timeout(10)->get($payload['jwks_uri']);
        $jwks = $jwksResponse->successful() ? $jwksResponse->json() : null;
        if (! is_array($jwks)) {
            throw new RuntimeException('No se pudo obtener el JWKS de Operations.');
        }
        $claims = $this->verifier->verify($payload['document'], $jwks);
        $installationId = (string) ($claims['installation_public_id'] ?? '');
        $audience = is_array($claims['aud'] ?? null) ? ($claims['aud'][0] ?? '') : (string) ($claims['aud'] ?? '');
        try {
            $this->verifier->assertTemporalClaims(
                $claims,
                (int) config('waadby_operations.remote_agent.enrollment_document_maximum_ttl_seconds', 300),
                (int) config('waadby_operations.remote_agent.clock_skew_seconds', 30),
            );
        } catch (RuntimeException) {
            throw new RuntimeException('El documento firmado de enrollment no es válido.');
        }
        if (($claims['iss'] ?? null) !== $origin
            || $installationId === ''
            || $audience !== 'urn:waadby:operations:installation:'.$installationId
            || ($claims['operations_issuer'] ?? null) !== $origin
            || ($claims['jwks_uri'] ?? null) !== $payload['jwks_uri']
            || ($claims['audience'] ?? null) !== $audience
            || ($claims['protocol_version'] ?? null) !== '1') {
            throw new RuntimeException('El documento firmado de enrollment no es válido.');
        }
        $applicationCode = $claims['application_code'] ?? null;
        $localApplicationCode = (string) config('waadby_operations.application.code');
        if (! is_string($applicationCode) || $applicationCode === '' || $localApplicationCode === '' || ! hash_equals($localApplicationCode, $applicationCode)) {
            throw new RuntimeException('El código de aplicación del enrollment no coincide con esta aplicación.');
        }
        $environment = $claims['environment'] ?? null;
        $localEnvironment = (string) config('waadby_operations.application.environment');
        if (! is_string($environment) || $environment === '' || $localEnvironment === '' || ! hash_equals($localEnvironment, $environment)) {
            throw new RuntimeException('El entorno del enrollment no coincide con esta aplicación.');
        }
        $identity = [
            'installation_id' => $installationId,
            'application_code' => $applicationCode,
            'environment' => $environment,
            'access_origin' => $origin,
            'issuer' => $origin,
            'audience' => $audience,
            'jwks_uri' => $payload['jwks_uri'],
            'jwks' => $jwks,
            'protocol_version' => '1',
            'enrolled_at' => now()->toIso8601String(),
        ];
        $this->store->put($identity);

        return $identity;
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        if ($host === '' || ! in_array($scheme, ['https', 'http'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('La URL de ACCESS no es válida.');
        }
        $origin = $scheme.'://'.$host.$port;
        if ($scheme !== 'https' && ! $this->localHttpAllowed($origin)) {
            throw new RuntimeException('ACCESS debe usar HTTPS.');
        }

        return $origin;
    }

    private function localHttpAllowed(string $origin): bool
    {
        $host = parse_url($origin, PHP_URL_HOST);

        return (bool) config('waadby_operations.remote_agent.allow_local_testing_http')
            && app()->environment('local', 'testing')
            && in_array($host, ['127.0.0.1', '::1'], true);
    }
}
