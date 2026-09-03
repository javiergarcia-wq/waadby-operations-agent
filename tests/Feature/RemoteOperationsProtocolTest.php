<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\ConsumerRuntime;
use Tests\Support\SignsOperationsRequests;
use Tests\TestCase;
use Waadby\OperationsAgent\Contracts\OperationsRuntime;

final class RemoteOperationsProtocolTest extends TestCase
{
    use SignsOperationsRequests;

    protected function setUp(): void
    {
        parent::setUp();
        ConsumerRuntime::reset();
        config(['waadby_operations.runtime' => ConsumerRuntime::class]);
        app()->forgetInstance(OperationsRuntime::class);
    }

    public function test_agent_is_fail_closed_when_disabled_not_enrolled_or_revoked(): void
    {
        config(['waadby_operations.remote_agent.enabled' => false]);
        $this->getJson('/waadby-operations/v1/inventory')->assertNotFound();

        config(['waadby_operations.remote_agent.enabled' => true]);
        $this->getJson('/waadby-operations/v1/inventory')->assertNotFound();

        $this->enrollTestAgent(revoked: true);
        $this->getJson('/waadby-operations/v1/inventory')->assertNotFound();
    }

    public function test_signature_replay_ttl_body_path_and_idempotency_are_enforced(): void
    {
        $this->enrollTestAgent();
        $correlation = (string) Str::uuid();
        $signature = $this->signedRequest('inventory', 'GET', '/waadby-operations/v1/inventory', correlation: $correlation);
        $headers = ['Authorization' => 'Signature '.$signature, 'X-Correlation-ID' => $correlation];
        $response = $this->withHeaders([...$headers, 'Accept' => 'application/json'])->get('/waadby-operations/v1/inventory');
        $response->assertOk();
        $this->withHeaders([...$headers, 'Accept' => 'application/json'])->get('/waadby-operations/v1/inventory')->assertUnauthorized()->assertJsonPath('error.code', 'request_replayed');

        $expired = $this->signedRequest('inventory', 'GET', '/waadby-operations/v1/inventory', overrides: ['iat' => time() - 120, 'nbf' => time() - 120, 'exp' => time() - 60]);
        $this->withHeaders(['Authorization' => 'Signature '.$expired, 'Accept' => 'application/json'])->get('/waadby-operations/v1/inventory')->assertUnauthorized();

        $body = '{"type":"operational"}';
        $idempotency = (string) Str::uuid();
        $tampered = $this->signedRequest('backup', 'POST', '/waadby-operations/v1/backup', $body, 'corr', $idempotency);
        $this->withHeaders(['Authorization' => 'Signature '.$tampered, 'X-Correlation-ID' => 'corr', 'Idempotency-Key' => $idempotency, 'Content-Type' => 'application/json'])
            ->call('POST', '/waadby-operations/v1/backup', [], [], [], [], '{"type":"disaster"}')
            ->assertUnauthorized();

        $wrongPath = $this->signedRequest('inventory', 'GET', '/waadby-operations/v1/other');
        $this->withHeaders(['Authorization' => 'Signature '.$wrongPath, 'Accept' => 'application/json'])->get('/waadby-operations/v1/inventory')->assertUnauthorized();

        $withoutIdempotency = $this->signedRequest('backup', 'POST', '/waadby-operations/v1/backup', $body);
        $this->withHeaders(['Authorization' => 'Signature '.$withoutIdempotency, 'Content-Type' => 'application/json'])
            ->call('POST', '/waadby-operations/v1/backup', [], [], [], [], $body)
            ->assertUnauthorized();
    }

    public function test_inventory_backup_verify_restore_and_update_delegate_to_custom_runtime(): void
    {
        $this->enrollTestAgent();
        $this->signedCall('GET', '/waadby-operations/v1/inventory', 'inventory')->assertOk();

        $backup = (string) Str::uuid();
        $this->signedCall('POST', '/waadby-operations/v1/backup', 'backup', ['type' => 'operational'])->assertAccepted();
        $this->signedCall('POST', "/waadby-operations/v1/backup/{$backup}/verify", 'backup_verify')->assertAccepted();
        $this->signedCall('POST', '/waadby-operations/v1/restore/preflight', 'restore_preflight', ['backup_id' => $backup])->assertAccepted();
        $this->signedCall('POST', '/waadby-operations/v1/update/preflight', 'update_preflight', ['manifest' => ['release_id' => 'release-1']])->assertAccepted();

        $this->assertSame(
            ['inventory', 'backup', 'verify', 'restorePreflight', 'updatePreflightDocument'],
            array_column(ConsumerRuntime::$calls, 'method'),
        );
    }

    public function test_mutation_rate_limit_is_enforced(): void
    {
        config(['waadby_operations.remote_agent.mutation_rate_limit_per_minute' => 1]);
        $this->enrollTestAgent();
        $this->signedCall('POST', '/waadby-operations/v1/backup', 'backup', ['type' => 'operational'])->assertAccepted();
        $this->signedCall('POST', '/waadby-operations/v1/backup', 'backup', ['type' => 'operational'])->assertTooManyRequests();
    }

    /** @param array<string, mixed> $payload */
    private function signedCall(string $method, string $path, string $operation, array $payload = []): TestResponse
    {
        $body = $method === 'GET' ? '' : json_encode($payload, JSON_THROW_ON_ERROR);
        $correlation = (string) Str::uuid();
        $idempotency = $method === 'POST' ? (string) Str::uuid() : '';
        $signature = $this->signedRequest($operation, $method, $path, $body, $correlation, $idempotency);
        $headers = ['Authorization' => 'Signature '.$signature, 'X-Correlation-ID' => $correlation, 'Accept' => 'application/json'];
        if ($idempotency !== '') {
            $headers['Idempotency-Key'] = $idempotency;
            $headers['Content-Type'] = 'application/json';
        }

        if ($method === 'GET') {
            return $this->withHeaders($headers)->get($path);
        }

        return $this->withHeaders($headers)->postJson($path, $payload);
    }
}
