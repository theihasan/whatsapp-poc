<?php

use App\Enums\DeviceStatus;

it('allows the documented pairing path', function () {
    expect(DeviceStatus::Created->canTransitionTo(DeviceStatus::Connecting))->toBeTrue()
        ->and(DeviceStatus::Connecting->canTransitionTo(DeviceStatus::QrReady))->toBeTrue()
        ->and(DeviceStatus::QrReady->canTransitionTo(DeviceStatus::Connected))->toBeTrue();
});

it('refuses transitions that would come from stale events', function (DeviceStatus $from, DeviceStatus $to) {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    'QR after connected' => [DeviceStatus::Connected, DeviceStatus::QrReady],
    'connected after logout' => [DeviceStatus::LoggedOut, DeviceStatus::Connected],
    'connected after manual disconnect' => [DeviceStatus::Disconnected, DeviceStatus::Connected],
    'connected without connecting' => [DeviceStatus::Created, DeviceStatus::Connected],
]);
