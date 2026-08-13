<?php

namespace Waadby\OperationsAgent\Http\Middleware;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Waadby\OperationsAgent\Contracts\OperationsReporter;
use Waadby\OperationsAgent\Remote\EnrollmentStore;
use Waadby\OperationsAgent\Remote\JwkTokenVerifier;

final class VerifyRemoteOperationsRequest
{
    public function __construct(
        private readonly EnrollmentStore $enrollment,
        private readonly JwkTokenVerifier $verifier,
        private readonly CacheManager $cache,
        private readonly OperationsReporter $reporter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('waadby_operations.remote_agent.enabled') || ! $this->enrollment->isReady()) {
            return response()->json(['error' => ['code' => 'remote_agent_unavailable', 'message' => 'Remote Operations no está disponible.']], 404);
        }
        if (strlen($request->getContent()) > (int) config('waadby_operations.remote_agent.maximum_body_bytes', 262144)) {
            return response()->json(['error' => ['code' => 'payload_too_large', 'message' => 'El cuerpo supera el límite permitido.']], 413);
        }
        $authorization = (string) $request->header('Authorization');
        if (! str_starts_with($authorization, 'Signature ')) {
            return $this->denied($request, 'signature_required');
        }
        $identity = null;
        try {
            $identity = $this->enrollment->get();
            if (! is_array($identity)) {
                throw new \RuntimeException('enrollment_unavailable');
            }
            $claims = $this->verifier->verify(substr($authorization, 10), $identity['jwks']);
            $this->assertClaims($request, $claims, $identity);
            $storeName = config('waadby_operations.remote_agent.replay_store');
            $store = $this->cache->store($storeName ?: null);
            $configuredStore = $storeName ?: config('cache.default');
            if (app()->environment('production') && config('cache.stores.'.$configuredStore.'.driver') === 'array') {
                throw new \RuntimeException('replay_store_unavailable');
            }
            $ttl = max(1, JwkTokenVerifier::timestamp($claims['exp']) - time() + (int) config('waadby_operations.remote_agent.clock_skew_seconds', 30));
            if (! $store->add('waadby-operations-jti:'.hash('sha256', (string) $claims['jti']), true, $ttl)) {
                throw new \RuntimeException('request_replayed');
            }
            $request->attributes->set('waadby_operations_claims', $claims);
        } catch (\RuntimeException $exception) {
            $code = in_array($exception->getMessage(), ['request_replayed', 'replay_store_unavailable', 'signature_claims_invalid'], true)
                ? $exception->getMessage()
                : 'signature_invalid';

            return $this->denied($request, $code, $identity);
        }

        return $next($request);
    }

    /** @param array<string, mixed> $claims @param array<string, mixed> $identity */
    private function assertClaims(Request $request, array $claims, array $identity): void
    {
        $skew = (int) config('waadby_operations.remote_agent.clock_skew_seconds', 30);
        $this->verifier->assertTemporalClaims(
            $claims,
            (int) config('waadby_operations.remote_agent.maximum_token_ttl_seconds', 60),
            $skew,
        );
        $audience = is_array($claims['aud'] ?? null) ? ($claims['aud'][0] ?? '') : ($claims['aud'] ?? '');
        $expectedAudience = 'urn:waadby:operations:installation:'.$identity['installation_id'];
        $expectedPath = '/'.$request->path();
        $idempotency = (string) $request->header('Idempotency-Key', '');
        $correlation = (string) $request->header('X-Correlation-ID', '');
        $checks = [
            ($claims['iss'] ?? null) === $identity['access_origin'],
            $audience === $expectedAudience,
            is_string($claims['jti'] ?? null) && $claims['jti'] !== '',
            strtoupper((string) ($claims['method'] ?? '')) === $request->getMethod(),
            ($claims['path'] ?? null) === $expectedPath,
            hash_equals((string) ($claims['body_sha256'] ?? ''), hash('sha256', $request->getContent())),
            ($claims['correlation_id'] ?? null) === $correlation,
            ($claims['idempotency_key'] ?? '') === $idempotency,
            ($claims['operation'] ?? null) === $this->operationFor($expectedPath, $request->getMethod()),
            ! $request->isMethod('post') || $idempotency !== '',
        ];
        if (in_array(false, $checks, true)) {
            throw new \RuntimeException('signature_claims_invalid');
        }
    }

    private function operationFor(string $path, string $method): string
    {
        return match (true) {
            $method === 'GET' && $path === '/waadby-operations/v1/inventory' => 'inventory',
            $method === 'GET' && preg_match('#^/waadby-operations/v1/operations/[0-9a-f-]{36}$#i', $path) === 1 => 'operation_status',
            $method === 'POST' && $path === '/waadby-operations/v1/backup' => 'backup',
            $method === 'POST' && preg_match('#^/waadby-operations/v1/backup/[0-9a-f-]{36}/verify$#i', $path) === 1 => 'backup_verify',
            $method === 'POST' && $path === '/waadby-operations/v1/restore/preflight' => 'restore_preflight',
            $method === 'POST' && $path === '/waadby-operations/v1/update/preflight' => 'update_preflight',
            default => 'unsupported',
        };
    }

    /** @param array<string, mixed>|null $identity */
    private function denied(Request $request, string $code, ?array $identity = null): Response
    {
        try {
            $path = preg_replace('#[^A-Za-z0-9/_\-.]#', '', '/'.ltrim($request->path(), '/')) ?: '/';
            $this->reporter->audit('operations.remote.signature_rejected', [
                'reason_code' => $code,
                'timestamp' => now()->utc()->toIso8601String(),
                'installation_public_id' => is_string($identity['installation_id'] ?? null) ? $identity['installation_id'] : null,
                'request_method' => strtoupper($request->getMethod()),
                'request_path' => substr($path, 0, 255),
            ]);
        } catch (\Throwable) {
            // Rejection auditing is best-effort and must never weaken fail-closed authentication.
        }

        return response()->json(['error' => ['code' => $code, 'message' => 'La solicitud firmada no es válida.']], 401);
    }
}
