<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DeviceApiController;
use App\Http\Controllers\CameraCaptureController;

Route::prefix('v1')->middleware(['throttle:120,1', 'device'])->group(function () {
    Route::post('/events', [DeviceApiController::class, 'store']);
    Route::post('/heartbeat', [DeviceApiController::class, 'heartbeat']);
});

Route::prefix('v1/camera')->middleware('device')->group(function () {
    Route::get('/pending', [CameraCaptureController::class, 'pending'])
        ->middleware('throttle:camera-pending');
    Route::post('/captures/{verification}', [CameraCaptureController::class, 'store'])
        ->middleware('throttle:camera-capture')->whereNumber('verification');
});
