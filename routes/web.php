<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LeadActionController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\ServicePackageController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::resource('leads', LeadController::class)->except('destroy');
    Route::prefix('leads/{lead}')->name('leads.')->controller(LeadActionController::class)->group(function () {
        Route::post('status', 'status')->name('status');
        Route::post('follow-up', 'followUp')->name('follow-up');
        Route::post('notes', 'note')->name('notes');
        Route::post('opt-out', 'optOut')->name('opt-out');
        Route::post('convert', 'convert')->name('convert');
    });

    Route::resource('customers', CustomerController::class)->except('destroy');
    Route::resource('service-packages', ServicePackageController::class)->except(['show', 'destroy']);

    Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit.index');
});
