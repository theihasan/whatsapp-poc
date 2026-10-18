<?php

use App\Enums\TemplateStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\WorkspaceSettings;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['name' => 'Sam Agent']));
});

it('creates a template', function () {
    $this->post(route('templates.store'), [
        'name' => 'Greeting',
        'category' => 'General',
        'body' => 'Hi {{contact_name}}',
        'status' => 'active',
    ])->assertRedirect();

    expect(MessageTemplate::query()->sole())->name->toBe('Greeting')->status->toBe(TemplateStatus::Active);
});

it('rejects unknown placeholders', function () {
    $this->post(route('templates.store'), ['name' => 'X', 'body' => 'Your code is {{otp}}', 'status' => 'draft'])
        ->assertSessionHasErrors(['body' => 'Unknown placeholder: {{otp}}.']);
});

it('previews a body with sample data', function () {
    $this->postJson(route('templates.preview'), ['body' => 'Hello {{contact_name}} from {{company_name}}'])
        ->assertOk()
        ->assertJson(['rendered' => 'Hello Alex Doe from Acme Support']);
});

it('renders only active templates for a conversation with real values', function () {
    app(WorkspaceSettings::class)->update(['workspace_name' => 'Northwind']);
    $conversation = Conversation::factory()->for(Contact::factory()->state(['name' => 'Priya']))->create();
    MessageTemplate::factory()->create(['name' => 'Live', 'body' => 'Hi {{contact_name}}, {{agent_name}} at {{company_name}}', 'status' => 'active']);
    MessageTemplate::factory()->create(['name' => 'Draft', 'status' => 'draft']);

    $this->getJson(route('conversations.templates', $conversation))
        ->assertOk()
        ->assertJsonCount(1, 'templates')
        ->assertJsonPath('templates.0.rendered', 'Hi Priya, Sam Agent at Northwind');
});

it('searches templates by name or text', function () {
    MessageTemplate::factory()->create(['name' => 'Refund policy', 'body' => 'Refunds take 5 days']);
    MessageTemplate::factory()->create(['name' => 'Hours', 'body' => 'We open at 9']);

    $this->get(route('templates.index', ['search' => 'refund']))
        ->assertInertia(fn ($page) => $page->component('templates/index')->has('templates', 1)->where('templates.0.name', 'Refund policy'));
});
