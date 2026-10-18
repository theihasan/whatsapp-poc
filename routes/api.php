<?php

use App\Http\Controllers\Gateway\GatewayWebhookController;
use App\Http\Middleware\VerifyGatewaySignature;
use Illuminate\Support\Facades\Route;

Route::post('gateway/events', GatewayWebhookController::class)
    ->middleware([VerifyGatewaySignature::class, 'throttle:gateway-webhook'])
    ->name('gateway.events');
