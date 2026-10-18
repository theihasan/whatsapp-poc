<?php

use App\Http\Controllers\Activity\ActivityController;
use App\Http\Controllers\Automations\AutomationRuleController;
use App\Http\Controllers\Contacts\ContactAvatarController;
use App\Http\Controllers\Contacts\ContactConsentController;
use App\Http\Controllers\Contacts\ContactController;
use App\Http\Controllers\Conversations\ConversationController;
use App\Http\Controllers\Conversations\ConversationMessageController;
use App\Http\Controllers\Conversations\InboxController;
use App\Http\Controllers\Conversations\MarkConversationReadController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Devices\DeviceController;
use App\Http\Controllers\Devices\DeviceSessionController;
use App\Http\Controllers\Devices\MockDeviceSimulationController;
use App\Http\Controllers\Messages\AttachmentController;
use App\Http\Controllers\Templates\TemplateController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::post('notifications/read', [DashboardController::class, 'readNotifications'])->name('notifications.read');

    Route::resource('devices', DeviceController::class)->except(['create', 'edit']);
    Route::post('devices/{device}/connect', [DeviceSessionController::class, 'connect'])->middleware('throttle:10,1')->name('devices.connect');
    Route::post('devices/{device}/disconnect', [DeviceSessionController::class, 'disconnect'])->name('devices.disconnect');
    Route::post('devices/{device}/revoke', [DeviceSessionController::class, 'revoke'])->name('devices.revoke');
    Route::get('devices/{device}/qr', [DeviceSessionController::class, 'qr'])->name('devices.qr');
    Route::post('devices/{device}/simulate/scan', [MockDeviceSimulationController::class, 'scan'])->name('devices.simulate.scan');
    Route::get('inbox', InboxController::class)->name('inbox');
    Route::get('inbox/{conversation}', InboxController::class)->name('inbox.show');
    Route::post('conversations', [ConversationController::class, 'store'])->name('conversations.store');
    Route::patch('conversations/{conversation}', [ConversationController::class, 'update'])->name('conversations.update');
    Route::post('conversations/{conversation}/read', MarkConversationReadController::class)->name('conversations.read');
    Route::get('conversations/{conversation}/messages', [ConversationMessageController::class, 'index'])->name('conversations.messages.index');
    Route::post('conversations/{conversation}/messages', [ConversationMessageController::class, 'store'])->middleware('throttle:send-message')->name('conversations.messages.store');
    Route::post('messages/{message}/retry', [ConversationMessageController::class, 'retry'])->name('messages.retry');

    Route::resource('contacts', ContactController::class)->except(['create', 'edit']);
    Route::get('contacts/{contact}/avatar', ContactAvatarController::class)->name('contacts.avatar');
    Route::post('contacts/{contact}/consents', [ContactConsentController::class, 'store'])->name('contacts.consents.store');
    Route::delete('contacts/{contact}/consents/{consent}', [ContactConsentController::class, 'destroy'])->scopeBindings()->name('contacts.consents.destroy');

    Route::post('templates/preview', [TemplateController::class, 'preview'])->name('templates.preview');
    Route::resource('templates', TemplateController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('conversations/{conversation}/templates', [TemplateController::class, 'forConversation'])->name('conversations.templates');

    Route::resource('automations', AutomationRuleController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::patch('automations/{automation}/toggle', [AutomationRuleController::class, 'toggle'])->name('automations.toggle');

    Route::get('activity', ActivityController::class)->name('activity');

    Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    Route::post('devices/{device}/simulate/incoming-message', [MockDeviceSimulationController::class, 'incomingMessage'])->name('devices.simulate.incoming');
});

require __DIR__.'/settings.php';
