<?php

use Illuminate\Support\Facades\Route;
use Waadby\OperationsAgent\Http\Controllers\RemoteOperationsController;
use Waadby\OperationsAgent\Http\Middleware\VerifyRemoteOperationsRequest;

Route::prefix('waadby-operations/v1')->middleware([VerifyRemoteOperationsRequest::class, 'throttle:120,1'])->group(function (): void {
    Route::get('/inventory', [RemoteOperationsController::class, 'inventory']);
    Route::post('/backup', [RemoteOperationsController::class, 'backup']);
    Route::post('/backup/{backup}/verify', [RemoteOperationsController::class, 'verify']);
    Route::get('/operations/{operation}', [RemoteOperationsController::class, 'operation']);
    Route::post('/restore/preflight', [RemoteOperationsController::class, 'restorePreflight']);
    Route::post('/update/preflight', [RemoteOperationsController::class, 'updatePreflight']);
});
