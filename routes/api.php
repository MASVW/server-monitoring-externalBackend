<?php

use App\Http\Controllers\Api\Admin\IncidentController;
use App\Http\Controllers\Api\HeartbeatController;
use App\Http\Controllers\Api\StatusController;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/heartbeat', [HeartbeatController::class, 'store'])
        ->middleware(['throttle:heartbeat', 'heartbeat.body']);

    Route::get('/status/{nodeId}', [StatusController::class, 'show']);

    Route::prefix('admin')->middleware('admin.token')->group(function (): void {
        Route::get('/incidents', [IncidentController::class, 'index']);
    });

    Route::fallback(function () {
        return ApiResponse::error('Route not found', 'Not Found', 404);
    });
});
