<?php

use Illuminate\Support\Facades\Route;
use Waadby\OperationsAgent\Http\Controllers\RemoteOperationsController;
use Waadby\OperationsAgent\Http\Middleware\VerifyRemoteOperationsRequest;

Route::prefix('waadby-operations/v1')->middleware('throttle:waadby-operations-agent')->group(function (): void {
    Route::middleware(VerifyRemoteOperationsRequest::class)->group(function (): void {
        Route::get('/inventory', [RemoteOperationsController::class, 'inventory']);
        Route::get('/operations/{operation}', [RemoteOperationsController::class, 'operation']);
    });

    Route::middleware(['throttle:waadby-operations-agent-mutations', VerifyRemoteOperationsRequest::class])->group(function (): void {
        Route::post('/backup', [RemoteOperationsController::class, 'backup']);
        Route::post('/backup/{backup}/verify', [RemoteOperationsController::class, 'verify']);
        Route::post('/restore/preflight', [RemoteOperationsController::class, 'restorePreflight']);
        Route::post('/update/preflight', [RemoteOperationsController::class, 'updatePreflight']);
    });
});
