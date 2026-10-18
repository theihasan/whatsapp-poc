<?php

use App\Models\AuditLog;
use App\Models\User;

it('redirects guests to the login page', function () {
    $this->get(route('activity'))->assertRedirect(route('login'));
});

it('filters the audit log by area', function () {
    $user = User::factory()->create();
    AuditLog::query()->create(['user_id' => $user->id, 'action' => 'device.created']);
    AuditLog::query()->create(['user_id' => $user->id, 'action' => 'contact.created']);

    $this->actingAs($user)
        ->get(route('activity', ['area' => 'device']))
        ->assertInertia(fn ($page) => $page->component('activity/index')->has('logs.data', 1)->where('logs.data.0.action', 'device.created'));
});
