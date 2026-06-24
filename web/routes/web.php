<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Web\DeviceController;
use App\Http\Controllers\Web\PolicyRuleController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::resource('devices', DeviceController::class)->only(['index', 'create', 'store', 'show']);
    Route::post('/devices/{device}/rules', [PolicyRuleController::class, 'store'])->name('devices.rules.store');
    Route::delete('/devices/{device}/rules/{ruleId}', [PolicyRuleController::class, 'destroy'])->name('devices.rules.destroy');

    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
});
