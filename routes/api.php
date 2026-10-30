<?php

use App\Http\Controllers\Api\ClientsController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\LocalRunnerController;
use App\Http\Controllers\Api\SchedulerLogController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\SetupController;
use Illuminate\Support\Facades\Route;

Route::get('/setup/status', [SetupController::class, 'status']);
Route::post('/setup', [SetupController::class, 'store']);
Route::get('/clients', [ClientsController::class, 'index']);
Route::prefix('hosted-clients')->middleware('throttle:30,1')->group(function () {
    Route::get('', [ClientsController::class, 'hosted']);
    Route::post('', [ClientsController::class, 'store']);
    Route::put('/{id}', [ClientsController::class, 'update'])->whereNumber('id');
    Route::post('/{id}/mobile-api-key', [ClientsController::class, 'generateKey'])->whereNumber('id');
    Route::delete('/{id}/mobile-api-key', [ClientsController::class, 'removeKey'])->whereNumber('id');
});
Route::get('/dashboard', DashboardController::class);
Route::prefix('sync')->group(function () {
    Route::get('/calendar', [SyncController::class, 'calendar']);
    Route::post('/pull-status', [SyncController::class, 'pullStatus'])->middleware('throttle:10,1');
    Route::post('/days', [SyncController::class, 'days'])->middleware('throttle:30,1');
    Route::post('/cancel', [SyncController::class, 'cancel'])->middleware('throttle:10,1');
    Route::post('/run-now', [SyncController::class, 'runNow'])->middleware('throttle:10,1');
    Route::post('/master', [SyncController::class, 'master'])->middleware('throttle:10,1');
});
Route::prefix('local-runner')->group(function () {
    Route::get('/settings', [LocalRunnerController::class, 'settings']);
    Route::put('/settings', [LocalRunnerController::class, 'saveSettings']);
    Route::get('/tenants', [LocalRunnerController::class, 'tenants']);
    Route::post('/test', [LocalRunnerController::class, 'test']);
    Route::get('/runs', [LocalRunnerController::class, 'runs']);
    Route::post('/runs', [LocalRunnerController::class, 'start']);
    Route::post('/runs/{id}/retry', [LocalRunnerController::class, 'retry'])->whereNumber('id');
    Route::post('/scheduler', [LocalRunnerController::class, 'scheduler']);
    Route::put('/scheduler/clients', [ClientsController::class, 'schedule']);
    Route::get('/logs', SchedulerLogController::class);
});
