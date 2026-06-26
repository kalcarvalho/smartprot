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
    Route::patch('/devices/{device}/icon-visibility', [PolicyRuleController::class, 'toggleIconVisibility'])->name('devices.icon-visibility.update');
    Route::patch('/devices/{device}/default-network', [PolicyRuleController::class, 'toggleDefaultNetwork'])->name('devices.default-network.update');
    Route::post('/devices/{device}/rules', [PolicyRuleController::class, 'store'])->name('devices.rules.store');
    Route::put('/devices/{device}/rules/{ruleId}', [PolicyRuleController::class, 'editRule'])->name('devices.rules.edit');
    Route::patch('/devices/{device}/rules/{ruleId}', [PolicyRuleController::class, 'update'])->name('devices.rules.update');
    Route::delete('/devices/{device}/rules/{ruleId}', [PolicyRuleController::class, 'destroy'])->name('devices.rules.destroy');
    Route::post('/devices/{device}/rules/{ruleId}/duplicate', [PolicyRuleController::class, 'duplicateRule'])->name('devices.rules.duplicate');
    Route::post('/devices/{device}/rules/{ruleId}/copy-to', [PolicyRuleController::class, 'copyRuleToDevice'])->name('devices.rules.copy-to');

    Route::get('/devices/{device}/domains', [DeviceDomainController::class, 'index'])->name('devices.domains.index');
    Route::patch('/devices/{device}/domains/{domain}', [DeviceDomainController::class, 'associate'])->name('devices.domains.associate');
    Route::get('/devices/{device}/apps', [DeviceDomainController::class, 'apps'])->name('devices.apps.index');
    Route::patch('/devices/{device}/apps/{appPackage}/block', [PolicyRuleController::class, 'toggleAppBlock'])->name('devices.apps.toggle-block');

    Route::get('/app-domain-mappings', [AppDomainMappingController::class, 'index'])->name('app-domain-mappings.index');
    Route::post('/app-domain-mappings', [AppDomainMappingController::class, 'store'])->name('app-domain-mappings.store');
    Route::delete('/app-domain-mappings/{mapping}', [AppDomainMappingController::class, 'destroy'])->name('app-domain-mappings.destroy');

    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
});