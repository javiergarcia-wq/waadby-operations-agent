<?php

namespace Waadby\OperationsAgent\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Waadby\OperationsAgent\Contracts\OperationsReporter;
use Waadby\OperationsAgent\Jobs\ExecuteUpdateSession;
use Waadby\OperationsAgent\Remote\EnrollmentStore;
use Waadby\OperationsAgent\Services\ReleaseManifestValidator;
use Waadby\OperationsAgent\Services\UpdatePreflightService;
use Waadby\OperationsAgent\Updates\ReleasePackageVerifier;
use Waadby\OperationsAgent\Updates\ReleaseSignatureService;
use Waadby\OperationsAgent\Updates\UpdateSessionStore;

final class RemoteUpdateController
{
    public function __construct(private readonly UpdateSessionStore $sessions, private readonly EnrollmentStore $enrollment, private readonly ReleaseManifestValidator $manifests, private readonly ReleaseSignatureService $signatures, private readonly UpdatePreflightService $preflight, private readonly ReleasePackageVerifier $packages, private readonly OperationsReporter $reporter) {}

    public function prepare(Request $request): JsonResponse
    {
        if (! config('waadby_operations.updates.agent_enabled', false)) {
            return $this->error('update_agent_disabled', 404);
        }
        $payload = $request->validate(['manifest' => ['required', 'array'], 'signature' => ['required', 'array'], 'package_size' => ['required', 'integer', 'min:1'], 'package_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/i'], 'backup_id' => ['required', 'uuid'], 'vault_verified' => ['required', 'boolean']]);
        try {
            $errors = $this->manifests->errors($payload['manifest']);
            if ($errors !== []) {
                throw new RuntimeException($errors[0]);
            }
            $this->signatures->verify($payload['manifest'], $payload['signature']);
            if (! hash_equals(strtolower((string) $payload['manifest']['package_sha256']), strtolower($payload['package_sha256']))) {
                throw new RuntimeException('package_sha256 no coincide con manifest.');
            }
            if ($payload['package_size'] > (int) config('waadby_operations.updates.maximum_package_bytes')) {
                throw new RuntimeException('El package supera el limite permitido.');
            }
            $backup = $this->reporter->findArtifact($payload['backup_id']);
            if (! is_array($backup) || ($backup['status'] ?? null) !== 'verified') {
                throw new RuntimeException('El backup PRE-UPDATE especifico no esta VERIFIED en el agente.');
            }
            if ((string) config('waadby_operations.application.environment') === 'production' && config('waadby_operations.updates.require_vault_production', true) && $payload['vault_verified'] !== true) {
                throw new RuntimeException('Produccion exige confirmacion firmada de Vault VERIFIED.');
            }
            $identity = $this->enrollment->get();
            if (! is_array($identity) || ! hash_equals((string) config('waadby_operations.application.code'), (string) $payload['manifest']['application_code'])) {
                throw new RuntimeException('El release no corresponde a esta instalacion.');
            }
            $result = $this->preflight->analyze($payload['manifest'], (string) $request->header('Idempotency-Key').':prepare');
            if (! $result['compatible']) {
                throw new RuntimeException($result['blockers'][0] ?? 'Preflight remoto incompatible.');
            }
            $session = $this->sessions->create([...$payload, 'installation_id' => $identity['installation_id']]);

            return response()->json(['session' => $session], 201);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => ['code' => 'update_prepare_rejected', 'message' => $exception->getMessage()]], 409);
        }
    }

    public function chunk(Request $request, string $session, int $index): JsonResponse
    {
        if (! config('waadby_operations.updates.agent_enabled', false)) {
            return $this->error('update_agent_disabled', 404);
        }
        try {
            $offset = filter_var($request->header('X-WAADBY-Chunk-Offset'), FILTER_VALIDATE_INT);
            if ($offset === false) {
                throw new RuntimeException('Falta el offset firmado del chunk.');
            }

            return response()->json(['session' => $this->sessions->append($session, $index, $offset, $request->getContent())]);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => ['code' => 'chunk_rejected', 'message' => $exception->getMessage()]], 409);
        }
    }

    public function finalize(string $session): JsonResponse
    {
        if (! config('waadby_operations.updates.agent_enabled', false)) {
            return $this->error('update_agent_disabled', 404);
        }
        try {
            $state = $this->sessions->get($session, true);
            $package = $this->sessions->packagePath($session);
            if (! is_file($package) || filesize($package) !== $state['expected_size'] || ! hash_equals($state['expected_sha'], hash_file('sha256', $package))) {
                throw new RuntimeException('El package final no coincide en size/SHA-256.');
            }
            $this->packages->verify($state['manifest'], $state['signature'], $package);

            return response()->json(['session' => $this->sessions->update($session, ['status' => 'staged'])]);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => ['code' => 'finalize_rejected', 'message' => $exception->getMessage()]], 409);
        }
    }

    public function apply(string $session): JsonResponse
    {
        if (! config('waadby_operations.updates.agent_enabled', false)) {
            return $this->error('update_agent_disabled', 404);
        }
        if (app()->environment('production') && config('queue.default') === 'sync') {
            return $this->error('asynchronous_queue_required', 503);
        }
        try {
            $state = $this->sessions->get($session, true);
            if ($state['status'] !== 'staged') {
                throw new RuntimeException('La sesion no esta staged.');
            }
            $this->sessions->update($session, ['status' => 'queued']);
            Bus::dispatch(new ExecuteUpdateSession($session));

            return response()->json(['session' => $this->sessions->get($session)], 202);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => ['code' => 'apply_rejected', 'message' => $exception->getMessage()]], 409);
        }
    }

    public function show(string $session): JsonResponse
    {
        try {
            return response()->json(['session' => $this->sessions->get($session)]);
        } catch (RuntimeException) {
            return $this->error('update_session_not_found', 404);
        }
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => 'La operacion de update remoto no esta disponible.']], $status);
    }
}
