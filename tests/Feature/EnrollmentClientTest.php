<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\SignsOperationsRequests;
use Tests\TestCase;
use Waadby\OperationsAgent\Remote\EnrollmentClient;
use Waadby\OperationsAgent\Remote\EnrollmentStore;

final class EnrollmentClientTest extends TestCase
{
    use SignsOperationsRequests;

    public function test_enrollment_validates_jwks_and_persists_bound_identity_without_token(): void
    {
        $token = 'one-time-secret-token';
        $this->fakeEnrollment($this->enrollmentDocument());

        $identity = app(EnrollmentClient::class)->enroll('http://127.0.0.1', $token);

        $this->assertSame('waadby-billing', $identity['application_code']);
        $this->assertSame('testing', $identity['environment']);
        $this->assertTrue(app(EnrollmentStore::class)->isReady());
        $this->assertStringNotContainsString($token, file_get_contents(app(EnrollmentStore::class)->path()));
        Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1/api/v1/operations/enrollments/claim'
            && $request['token'] === $token
            && $request['protocol_version'] === '1'
            && $request['capabilities']['inventory'] === true);
    }

    public function test_wrong_application_fails_closed(): void
    {
        $this->fakeEnrollment($this->enrollmentDocument(['application_code' => 'other-app']));
        try {
            app(EnrollmentClient::class)->enroll('http://127.0.0.1', 'token');
            $this->fail('Wrong application must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('código de aplicación', $exception->getMessage());
        }
        $this->assertNull(app(EnrollmentStore::class)->get());
    }

    public function test_wrong_environment_fails_closed(): void
    {
        $this->fakeEnrollment($this->enrollmentDocument(['environment' => 'production']));
        $this->expectExceptionMessage('El entorno del enrollment no coincide con esta aplicación.');
        app(EnrollmentClient::class)->enroll('http://127.0.0.1', 'token');
    }

    public function test_expired_document_fails_closed(): void
    {
        $this->fakeEnrollment($this->enrollmentDocument([
            'iat' => time() - 360,
            'nbf' => time() - 360,
            'exp' => time() - 60,
        ]));
        try {
            app(EnrollmentClient::class)->enroll('http://127.0.0.1', 'token');
            $this->fail('Expired enrollment must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('El documento firmado de enrollment no es válido.', $exception->getMessage());
        }
    }

    public function test_jwks_from_another_origin_fails_closed(): void
    {
        Http::fake([
            'http://127.0.0.1/api/v1/operations/enrollments/claim' => Http::response([
                'document' => $this->enrollmentDocument(),
                'jwks_uri' => 'https://different-origin.example/jwks.json',
            ]),
        ]);
        $this->expectExceptionMessage('El JWKS de enrollment pertenece a un origen distinto.');
        app(EnrollmentClient::class)->enroll('http://127.0.0.1', 'token');
    }

    /** @param array<string, mixed> $overrides */
    private function enrollmentDocument(array $overrides = []): string
    {
        $installationId = (string) config('tests.installation_id');
        $audience = 'urn:waadby:operations:installation:'.$installationId;

        return $this->signedRequest('enrollment', 'POST', '/api/v1/operations/enrollments/claim', overrides: array_replace([
            'operations_issuer' => 'http://127.0.0.1',
            'installation_public_id' => $installationId,
            'application_code' => 'waadby-billing',
            'environment' => 'testing',
            'jwks_uri' => 'http://127.0.0.1/.well-known/waadby-operations-jwks.json',
            'audience' => $audience,
            'protocol_version' => '1',
        ], $overrides));
    }

    private function fakeEnrollment(string $document): void
    {
        Http::fake([
            'http://127.0.0.1/api/v1/operations/enrollments/claim' => Http::response([
                'document' => $document,
                'jwks_uri' => 'http://127.0.0.1/.well-known/waadby-operations-jwks.json',
            ]),
            'http://127.0.0.1/.well-known/waadby-operations-jwks.json' => Http::response($this->operationsJwks()),
        ]);
    }
}
