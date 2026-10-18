<?php

use App\Enums\DeviceStatus;
use App\Enums\MessagingProviderName;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WhatsAppAccount;

it('redirects guests to the login page', function () {
    $this->get(route('devices.index'))->assertRedirect(route('login'));
});

describe('index', function () {
    it('lists devices with only enabled providers offered', function () {
        config(['messaging.unofficial_enabled' => false]);
        $device = WhatsAppAccount::factory()->create(['name' => 'Front desk']);

        $this->actingAs(User::factory()->create())
            ->get(route('devices.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('devices/index')
                ->where('devices.0.id', $device->id)
                ->where('devices.0.status', 'created')
                ->where('providers', [['value' => 'mock', 'label' => 'Mock (simulated)']]));
    });
});

describe('store', function () {
    it('creates a device on the chosen provider in the created state', function () {
        $this->actingAs(User::factory()->create())
            ->post(route('devices.store'), ['name' => 'Sales line', 'provider' => 'mock'])
            ->assertRedirect(route('devices.index'));

        $device = WhatsAppAccount::query()->sole();
        expect($device->name)->toBe('Sales line')
            ->and($device->provider)->toBe(MessagingProviderName::Mock)
            ->and($device->status)->toBe(DeviceStatus::Created);
        expect(AuditLog::query()->where('action', 'device.created')->exists())->toBeTrue();
    });

    it('rejects the unofficial provider unless it is explicitly enabled', function () {
        config(['messaging.unofficial_enabled' => false]);

        $this->actingAs(User::factory()->create())
            ->post(route('devices.store'), ['name' => 'Test phone', 'provider' => 'unofficial_web'])
            ->assertSessionHasErrors(['provider' => 'That provider is not enabled on this server.']);

        expect(WhatsAppAccount::query()->count())->toBe(0);
    });

    it('requires a name and provider', function () {
        $this->actingAs(User::factory()->create())
            ->post(route('devices.store'), [])
            ->assertSessionHasErrors(['name', 'provider']);
    });
});

describe('update', function () {
    it('renames a device', function () {
        $device = WhatsAppAccount::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patch(route('devices.update', $device), ['name' => 'Renamed'])
            ->assertRedirect();

        expect($device->fresh()->name)->toBe('Renamed');
    });
});

describe('destroy', function () {
    it('revokes the session before deleting the device', function () {
        $device = WhatsAppAccount::factory()->connected()->create();

        $this->actingAs(User::factory()->create())
            ->delete(route('devices.destroy', $device))
            ->assertRedirect(route('devices.index'));

        $this->assertModelMissing($device);
        expect(AuditLog::query()->pluck('action')->all())->toContain('device.revoked', 'device.deleted');
    });
});

describe('show', function () {
    it('renders the device with its connection history newest first', function () {
        $device = WhatsAppAccount::factory()->create();
        $device->connectionEvents()->create(['from_status' => 'created', 'to_status' => 'connecting', 'occurred_at' => now()->subMinute()]);
        $device->connectionEvents()->create(['from_status' => 'connecting', 'to_status' => 'failed', 'reason' => 'Gateway unreachable', 'occurred_at' => now()]);

        $this->actingAs(User::factory()->create())
            ->get(route('devices.show', $device))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('devices/show')
                ->where('device.id', $device->id)
                ->where('history.0.to_status', 'failed')
                ->where('history.0.reason', 'Gateway unreachable')
                ->has('history', 2));
    });
});
