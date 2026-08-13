<?php

namespace Waadby\OperationsAgent\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Waadby\OperationsAgent\Contracts\OperationsReporter;
use Waadby\OperationsAgent\Contracts\OperationsRuntime;
use Waadby\OperationsAgent\Jobs\ExecuteRemoteOperation;
use Waadby\OperationsAgent\Services\BackupExportService;

final class RemoteOperationsController
{
    public function __construct(private readonly OperationsRuntime $runtime, private readonly OperationsReporter $reporter, private readonly BackupExportService $exports) {}

    public function inventory(): JsonResponse
    {
        return response()->json(['inventory' => $this->safe($this->runtime->inventory(false, (string) Str::uuid()))]);
    }

    public function backup(Request $request): JsonResponse
    {
        $payload = $request->validate(['type' => ['required', 'in:operational,disaster']]);

        return $this->queue($request, 'backup', $payload);
    }

    public function verify(Request $request, string $backup): JsonResponse
    {
        abort_unless(Str::isUuid($backup), 404);

        return $this->queue($request, 'backup_verify', ['backup_id' => $backup]);
    }

    public function export(Request $request, string $backup): Response
    {
        abort_unless(Str::isUuid($backup), 404);
        if ($request->query() !== []) {
            return response()->json(['error' => ['code' => 'export_query_not_allowed', 'message' => 'La exportación solo admite el UUID del backup.']], 400);
        }
        try {
            return $this->exports->response($backup, $request->header('Range'));
        } catch (\RuntimeException $exception) {
            $status = in_array($exception->getCode(), [404, 409, 416], true) ? $exception->getCode() : 409;

            return response()->json(['error' => ['code' => $exception->getMessage(), 'message' => 'El backup no está disponible para exportación.']], $status);
        }
    }

    public function restorePreflight(Request $request): JsonResponse
    {
        return $this->queue($request, 'restore_preflight', $request->validate(['backup_id' => ['required', 'uuid']]));
    }

    public function updatePreflight(Request $request): JsonResponse
    {
        return $this->queue($request, 'update_preflight', $request->validate(['manifest' => ['required', 'array']]));
    }

    public function operation(string $operation): JsonResponse
    {
        abort_unless(Str::isUuid($operation), 404);
        $value = method_exists($this->reporter, 'findOperation') ? $this->reporter->findOperation($operation) : null;
        abort_unless($value, 404);

        return response()->json(['operation' => $this->safe(collect($value)->only([
            'public_id', 'operation_type', 'status', 'started_at', 'finished_at', 'summary', 'error_code', 'error_message_safe',
        ])->all())]);
    }

    /** @param array<string, mixed> $payload */
    private function queue(Request $request, string $type, array $payload): JsonResponse
    {
        if (app()->environment('production') && config('queue.default') === 'sync') {
            return response()->json(['error' => ['code' => 'asynchronous_queue_required', 'message' => 'La cola asíncrona del agente no está disponible.']], 503);
        }
        $idempotency = (string) $request->header('Idempotency-Key');
        $operation = method_exists($this->reporter, 'queueOperation')
            ? $this->reporter->queueOperation($type, $idempotency)
            : $this->reporter->beginOperation($type, $idempotency);
        if (($operation['status'] ?? null) === 'queued') {
            Bus::dispatch(new ExecuteRemoteOperation($type, $idempotency, $payload));
        }

        return response()->json(['operation' => $this->safe($operation)], 202);
    }

    private function safe(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $safe = [];
        foreach ($value as $key => $item) {
            if (preg_match('/^(?:manifest|files|entries)$|(?:^|_)(?:path|paths)$|(?:absolute|storage|package|private|secret|token|key).*path|(?:private|secret|token|password|encryption_key)/i', (string) $key)) {
                continue;
            }
            $safe[$key] = $this->safe($item);
        }

        return $safe;
    }
}
