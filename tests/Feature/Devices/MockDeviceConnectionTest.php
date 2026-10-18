<?php

use App\Enums\DeviceStatus;
use App\Models\User;
use App\Models\WhatsAppAccount;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('shows a temporary QR code after connecting and only reports connected after the scan', function () {
    $device = WhatsAppAccount::factory()->create();

    $this->post(route('devices.connect', $device))->assertRedirect();

    expect($device->fresh()->status)->toBe(DeviceStatus::QrReady);
    $qr = $this->getJson(route('devices.qr', $device))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->json();
    expect($qr['qr'])->toStartWith('MOCK-QR:');

    $this->post(route('devices.simulate.scan', $device))->assertRedirect();

    $device->refresh();
    expect($device->status)->toBe(DeviceStatus::Connected)
        ->and($device->phone_number)->toMatch('/^\+1\d{3}55501\d{2}$/');
    $this->getJson(route('devices.qr', $device))->assertJson(['qr' => null, 'status' => 'connected']);
});

it('records every transition in the connection history', function () {
    $device = WhatsAppAccount::factory()->create();

    $this->post(route('devices.connect', $device));
    $this->post(route('devices.simulate.scan', $device));

    expect($device->connectionEvents()->orderBy('id')->pluck('to_status')->map->value->all())
        ->toBe(['connecting', 'qr_ready', 'connected']);
});

it('expires the QR code after its time to live', function () {
    config(['messaging.qr_ttl' => 60]);
    $device = WhatsAppAccount::factory()->create();
    $this->post(route('devices.connect', $device));

    $this->travel(61)->seconds();

    $this->getJson(route('devices.qr', $device))->assertJson(['qr' => null]);
});

it('refuses a second connection attempt while one is active', function () {
    $device = WhatsAppAccount::factory()->create();
    $this->post(route('devices.connect', $device));

    $this->post(route('devices.connect', $device))
        ->assertInertiaFlash('toast.message', 'This device is already connecting or connected.');

    expect($device->connectionEvents()->where('to_status', 'connecting')->count())->toBe(1);
});

it('disconnects a connected device and allows it to connect again', function () {
    $device = WhatsAppAccount::factory()->connected()->create();

    $this->post(route('devices.disconnect', $device));

    expect($device->fresh()->status)->toBe(DeviceStatus::Disconnected);

    $this->post(route('devices.connect', $device));

    expect($device->fresh()->status)->toBe(DeviceStatus::QrReady);
});

it('revokes a session and marks the device logged out', function () {
    $device = WhatsAppAccount::factory()->connected()->create();

    $this->post(route('devices.revoke', $device));

    expect($device->fresh()->status)->toBe(DeviceStatus::LoggedOut);
});

it('rejects a simulated scan when no QR code is pending', function () {
    $device = WhatsAppAccount::factory()->create();

    $this->post(route('devices.simulate.scan', $device))
        ->assertInertiaFlash('toast.message', 'There is no QR code waiting to be scanned.');

    expect($device->fresh()->status)->toBe(DeviceStatus::Created);
});

it('returns 404 for simulation controls on devices that are not mocked', function () {
    config(['messaging.unofficial_enabled' => true]);
    $device = WhatsAppAccount::factory()->unofficial()->create(['status' => DeviceStatus::QrReady]);

    $this->post(route('devices.simulate.scan', $device))->assertNotFound();
});

it('restarts pairing with a fresh QR code once the previous one expired', function () {
    $device = WhatsAppAccount::factory()->create();
    $this->post(route('devices.connect', $device));
    $firstQr = $this->getJson(route('devices.qr', $device))->json('qr');

    $this->travel(61)->seconds();
    $this->post(route('devices.connect', $device));

    $secondQr = $this->getJson(route('devices.qr', $device))->json('qr');
    expect($secondQr)->not->toBeNull()->not->toBe($firstQr)
        ->and($device->fresh()->status)->toBe(DeviceStatus::QrReady);
});
