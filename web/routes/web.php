<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Web\AppDomainMappingController;
use App\Http\Controllers\Web\DeviceController;
use App\Http\Controllers\Web\DeviceDomainController;
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
    Route::get('/devices/{device}/events', [DeviceController::class, 'events'])->name('devices.events');
    Route::post('/devices/{device}/claim', [DeviceController::class, 'claim'])->name('devices.claim');
    Route::patch('/devices/{device}/protection', [PolicyRuleController::class, 'toggleProtection'])->name('devices.protection.update');
    Route::post('/devices/{device}/rules', [PolicyRuleController::class, 'store'])->name('devices.rules.store');
    Route::patch('/devices/{device}/rules/{ruleId}', [PolicyRuleController::class, 'update'])->name('devices.rules.update');
    Route::delete('/devices/{device}/rules/{ruleId}', [PolicyRuleController::class, 'destroy'])->name('devices.rules.destroy');

    Route::get('/devices/{device}/domains', [DeviceDomainController::class, 'index'])->name('devices.domains.index');
    Route::patch('/devices/{device}/domains/{domain}', [DeviceDomainController::class, 'associate'])->name('devices.domains.associate');

    Route::get('/app-domain-mappings', [AppDomainMappingController::class, 'index'])->name('app-domain-mappings.index');
    Route::post('/app-domain-mappings', [AppDomainMappingController::class, 'store'])->name('app-domain-mappings.store');
    Route::delete('/app-domain-mappings/{mapping}', [AppDomainMappingController::class, 'destroy'])->name('app-domain-mappings.destroy');

    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
});