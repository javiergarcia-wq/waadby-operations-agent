<?php

namespace Waadby\OperationsAgent\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Waadby\OperationsAgent\Contracts\OperationsReporter;
use Waadby\OperationsAgent\Jobs\ExecuteRestoreSession;
use Waadby\OperationsAgent\Remote\EnrollmentStore;
use Waadby\OperationsAgent\Restores\RestorePlan;
use Waadby\OperationsAgent\Restores\RestoreSessionStore;
use Waadby\OperationsAgent\Services\RestorePreflightService;

final class RemoteRestoreController
{
    public function __construct(private readonly RestoreSessionStore $sessions, private readonly RestorePreflightService $preflight, private readonly EnrollmentStore $enrollment, private readonly OperationsReporter $reporter) {}

    public function prepare(Request $request): JsonResponse
    {
        if (! config('waadby_operations.restores.agent_enabled', false)) {
            return $this->error('restore_agent_disabled', 404);
        }
        $payload = $request->validate([
            'backup_id' => ['required', 'uuid'],
            'artifact_id' => ['nullable', 'uuid', 'required_with:package_size'],
            'package_size' => ['nullable', 'integer', 'min:1'],
            'package_sha256' => ['nullable', 'regex:/^[a-f0-9]{64}$/i', 'required_with:package_size'],
        ]);
        try {
            $identity = $this->enrollment->get();
            if (! is_array($identity)) {
                throw new RuntimeException('El agente no esta enrolled.');
            }
            if (isset($payload['package_size'])) {
                if ($payload['package_size'] > (int) config('waadby_operations.restores.maximum_archive_bytes', 10737418240)) {
                    throw new RuntimeException('El backup restore supera el limite permitido.');
                }

                return response()->json(['session' => $this->sessions->createUpload($identity['installation_id'], $payload['backup_id'], $payload['artifact_id'], $payload['package_sha256'], $payload['package_size'])], 201);
            }
            $artifact = $this->reporter->findArtifact($payload['backup_id']);
            if (! is_array($artifact) || ($artifact['status'] ?? null) !== 'verified') {
                throw new RuntimeException('El origen remoto no es VERIFIED.');
            }
            $plan = $this->preflight->plan($payload['backup_id'], ['type' => 'remote_artifact', 'remote_artifact_id' => $payload['backup_id'], 'backup_id' => $payload['backup_id']]);

            return response()->json(['session' => $this->sessions->createLocal($identity['installation_id'], $payload['backup_id'], $plan)], 201);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => ['code' => 'restore_prepare_rejected', 'message' => $exception->getMessage()]], 409);
        }
    }

    public function chunk(Request $request, string $session, int $index): JsonResponse
    {
        if (! config('waadby_operations.restores.agent_enabled', false)) {
            return $this->error('restore_agent_disabled', 404);
        }
        try {
            $offset = filter_var($request->header('X-WAADBY-Chunk-Offset'), FILTER_VALIDATE_INT);
            if ($offset === false) {
                throw new RuntimeException('Falta el offset firmado del chunk restore.');
            }

            return response()->json(['session' => $this->sessions->append($session, $index, $offset, $request->getContent())]);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => ['code' => 'restore_chunk_rejected', 'message' => $exception->getMessage()]], 409);
        }
    }

    public function finalize(string $session): JsonResponse
    {
        if (! config('waadby_operations.restores.agent_enabled', false)) {
            return $this->error('restore_agent_disabled', 404);
        }
        try {
            $state = $this->sessions->get($session, true);
            $source = $this->sessions->sourcePath($session);
            if (! is_file($source) || filesize($source) !== $state['expected_size'] || ! hash_equals($state['expected_sha'], hash_file('sha256', $source))) {
                throw new RuntimeException('El backup restore final no coincide en size/SHA-256.');
            }
            $plan = $this->preflight->plan($source, [
                'type' => 'remote_artifact', 'backup_id' => $state['backup_reference'],
                'artifact_id' => $state['artifact_reference'], 'remote_artifact_id' => $state['backup_reference'], 'remote_session_id' => $session,
            ], portable: true);

            return response()->json(['session' => $this->sessions->finalize($session, $plan)]);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => ['code' => 'restore_finalize_rejected', 'message' => $exception->getMessage()]], 409);
        }
    }

    public function apply(Request $request, string $session): JsonResponse
    {
        if (! config('waadby_operations.restores.agent_enabled', false)) {
            return $this->error('restore_agent_disabled', 404);
        }
        if (app()->environment('production') && config('queue.default') === 'sync') {
            return $this->error('asynchronous_queue_required', 503);
        }
        $payload = $request->validate(['plan_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'], 'confirmation' => ['required', 'string'], 'reason' => ['required', 'string', 'min:10', 'max:2000'], 'safety_backup_id' => ['required', 'uuid'], 'safety_vault_verified' => ['required', 'boolean']]);
        try {
            $state = $this->sessions->get($session, true);
            RestorePlan::validate($state['plan']);
            if (! in_array($state['status'], ['prepared', 'staged'], true) || ! is_array($state['plan']) || ! hash_equals($state['plan']['plan_sha256'], strtolower($payload['plan_sha256'])) || ! hash_equals('RESTORE '.$state['plan']['target']['application_code'].' '.$state['plan']['source']['backup_id'], $payload['confirmation'])) {
                throw new RuntimeException('La autorizacion restore remota no coincide con el plan preparado.');
            }
            if ((string) $state['plan']['target']['environment'] === 'production' && config('waadby_operations.restores.require_safety_vault_production', true) && $payload['safety_vault_verified'] !== true) {
                throw new RuntimeException('Produccion exige confirmacion firmada de safety Vault VERIFIED.');
            }
            $safety = $this->reporter->findArtifact($payload['safety_backup_id']);
            if (! is_array($safety) || ($safety['status'] ?? null) !== 'verified') {
                throw new RuntimeException('El safety backup remoto especifico no es VERIFIED.');
            }
            $this->sessions->update($session, ['status' => 'queued', 'reason' => $payload['reason'], 'safety_backup_reference' => $payload['safety_backup_id'], 'safety_vault_verified' => $payload['safety_vault_verified']]);
            Bus::dispatch(new ExecuteRestoreSession($session));

            return response()->json(['session' => $this->sessions->get($session)], 202);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => ['code' => 'restore_apply_rejected', 'message' => $exception->getMessage()]], 409);
        }
    }

    public function show(string $session): JsonResponse
    {
        if (! config('waadby_operations.restores.agent_enabled', false)) {
            return $this->error('restore_agent_disabled', 404);
        }
        try {
            return response()->json(['session' => $this->sessions->get($session)]);
        } catch (RuntimeException) {
            return $this->error('restore_session_not_found', 404);
        }
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => 'La operacion restore remota no esta disponible.']], $status);
    }
}
