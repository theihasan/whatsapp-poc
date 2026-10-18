<?php

use App\Http\Controllers\Devices\DeviceController;
use App\Http\Controllers\Devices\DeviceSessionController;
use App\Http\Controllers\Devices\MockDeviceSimulationController;
use App\Http\Controllers\Messages\AttachmentController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::resource('devices', DeviceController::class)->except(['create', 'edit']);
    Route::post('devices/{device}/connect', [DeviceSessionController::class, 'connect'])->middleware('throttle:10,1')->name('devices.connect');
    Route::post('devices/{device}/disconnect', [DeviceSessionController::class, 'disconnect'])->name('devices.disconnect');
    Route::post('devices/{device}/revoke', [DeviceSessionController::class, 'revoke'])->name('devices.revoke');
    Route::get('devices/{device}/qr', [DeviceSessionController::class, 'qr'])->name('devices.qr');
    Route::post('devices/{device}/simulate/scan', [MockDeviceSimulationController::class, 'scan'])->name('devices.simulate.scan');
    Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    Route::post('devices/{device}/simulate/incoming-message', [MockDeviceSimulationController::class, 'incomingMessage'])->name('devices.simulate.incoming');
});

require __DIR__.'/settings.php';
