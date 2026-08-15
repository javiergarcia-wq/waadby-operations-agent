<?php

use Illuminate\Support\Facades\Route;
use Waadby\OperationsAgent\Http\Controllers\RemoteOperationsController;
use Waadby\OperationsAgent\Http\Controllers\RemoteRestoreController;
use Waadby\OperationsAgent\Http\Controllers\RemoteUpdateController;
use Waadby\OperationsAgent\Http\Middleware\VerifyRemoteOperationsRequest;

Route::prefix('waadby-operations/v1')->middleware('throttle:waadby-operations-agent')->group(function (): void {
    Route::middleware(VerifyRemoteOperationsRequest::class)->group(function (): void {
        Route::get('/inventory', [RemoteOperationsController::class, 'inventory']);
        Route::get('/operations/{operation}', [RemoteOperationsController::class, 'operation']);
        Route::get('/updates/{session}', [RemoteUpdateController::class, 'show']);
        Route::get('/restores/{session}', [RemoteRestoreController::class, 'show']);
    });

    Route::get('/backup/{backup}/export', [RemoteOperationsController::class, 'export'])
        ->middleware(['throttle:waadby-operations-agent-export', VerifyRemoteOperationsRequest::class]);

    Route::middleware(['throttle:waadby-operations-agent-mutations', VerifyRemoteOperationsRequest::class])->group(function (): void {
        Route::post('/backup', [RemoteOperationsController::class, 'backup']);
        Route::post('/backup/{backup}/verify', [RemoteOperationsController::class, 'verify']);
        Route::post('/restore/preflight', [RemoteOperationsController::class, 'restorePreflight']);
        Route::post('/update/preflight', [RemoteOperationsController::class, 'updatePreflight']);
        Route::post('/updates/prepare', [RemoteUpdateController::class, 'prepare']);
        Route::post('/updates/{session}/finalize', [RemoteUpdateController::class, 'finalize']);
        Route::post('/updates/{session}/apply', [RemoteUpdateController::class, 'apply']);
        Route::post('/restores/prepare', [RemoteRestoreController::class, 'prepare']);
        Route::post('/restores/{session}/finalize', [RemoteRestoreController::class, 'finalize']);
        Route::post('/restores/{session}/apply', [RemoteRestoreController::class, 'apply']);
    });
    Route::put('/updates/{session}/chunks/{index}', [RemoteUpdateController::class, 'chunk'])
        ->whereNumber('index')->middleware(['throttle:waadby-operations-agent-mutations', VerifyRemoteOperationsRequest::class]);
    Route::put('/restores/{session}/chunks/{index}', [RemoteRestoreController::class, 'chunk'])
        ->whereNumber('index')->middleware(['throttle:waadby-operations-agent-mutations', VerifyRemoteOperationsRequest::class]);
});
