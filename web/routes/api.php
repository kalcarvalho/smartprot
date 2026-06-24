<?php

use App\Http\Controllers\Api\V1\DeviceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/devices/register', [DeviceController::class, 'register']);
    Route::post('/devices/{device:public_id}/heartbeat', [DeviceController::class, 'heartbeat']);
    Route::get('/devices/{device:public_id}/policy', [DeviceController::class, 'policy']);
    Route::post('/devices/{device:public_id}/events', [DeviceController::class, 'storeEvent']);
});
