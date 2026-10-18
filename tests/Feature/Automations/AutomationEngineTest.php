<?php

use App\Actions\Gateway\RecordGatewayEvent;
use App\DTOs\GatewayEventData;
use App\Enums\AutomationExecutionStatus;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tag;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Notifications\AutomationTriggered;
use App\Services\Automation\AutomationEngine;
use App\Services\WorkspaceSettings;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->device = WhatsAppAccount::factory()->connected()->create();
    $this->contact = Contact::factory()->create(['name' => 'Lena']);
});

function incoming(string $body, ?Contact $contact = null): void
{
    $contact ??= test()->contact;

    app(RecordGatewayEvent::class)->handle(new GatewayEventData((string) Str::ulid(), 'message.received', 1, test()->device->id, now(), [
        'externalId' => 'IN-'.Str::random(8),
        'from' => ['jid' => $contact->wa_jid, 'phoneNumber' => $contact->phone_number],
        'type' => 'text',
        'body' => $body,
    ]));
}

it('matches keywords on word boundaries only', function () {
    AutomationRule::factory()->create(['conditions' => ['keywords' => ['price']], 'actions' => [['type' => 'suggest_reply', 'text' => 'See pricing']]]);

    incoming('This is priceless');
    expect(Conversation::query()->sole()->suggested_reply)->toBeNull();

    incoming('What is the PRICE?');
    expect(Conversation::query()->sole()->suggested_reply)->toBe('See pricing');
});

it('logs a dry run without changing anything', function () {
    $agent = User::factory()->create();
    $rule = AutomationRule::factory()->create([
        'is_dry_run' => true,
        'conditions' => ['keywords' => ['help']],
        'actions' => [['type' => 'assign', 'user_id' => $agent->id]],
    ]);

    incoming('help please');

    expect(Conversation::query()->sole()->assigned_user_id)->toBeNull();
    expect($rule->executions()->sole())
        ->status->toBe(AutomationExecutionStatus::DryRun)
        ->actions_taken->toBe(['would assign']);
});

it('rate limits a rule per conversation', function () {
    $rule = AutomationRule::factory()->create([
        'max_runs_per_conversation_per_hour' => 1,
        'conditions' => ['keywords' => ['hi']],
        'actions' => [['type' => 'suggest_reply', 'text' => 'Hello!']],
    ]);

    incoming('hi');
    incoming('hi again');

    expect($rule->executions()->pluck('status')->map->value->all())->toBe(['executed', 'rate_limited']);
});

it('skips automatic replies while they are disabled in settings', function () {
    AutomationRule::factory()->create(['conditions' => ['keywords' => ['hours']], 'actions' => [['type' => 'auto_reply', 'text' => 'We open at 9']]]);

    incoming('what are your hours');

    expect(Message::query()->where('direction', 'outgoing')->count())->toBe(0);
    expect(AutomationRule::query()->sole()->executions()->sole()->actions_taken)->toBe(['skipped auto_reply (disabled in settings)']);
});

it('sends an automated reply that does not retrigger rules', function () {
    app(WorkspaceSettings::class)->update(['auto_reply_enabled' => true]);
    AutomationRule::factory()->create([
        'max_runs_per_conversation_per_hour' => 10,
        'conditions' => ['keywords' => ['hours']],
        'actions' => [['type' => 'auto_reply', 'text' => 'Hi {{contact_name}}, our hours are 9-5']],
    ]);

    incoming('your hours?');

    $reply = Message::query()->where('direction', 'outgoing')->sole();
    expect($reply)
        ->body->toBe('Hi Lena, our hours are 9-5')
        ->is_automated->toBeTrue()
        ->external_id->toStartWith('MOCK-OUT-');
    expect(AutomationExecution::query()->count())->toBe(1);
});

it('suppresses a second automated reply inside the cooldown', function () {
    app(WorkspaceSettings::class)->update(['auto_reply_enabled' => true]);
    AutomationRule::factory()->create([
        'max_runs_per_conversation_per_hour' => 10,
        'conditions' => ['keywords' => ['hours']],
        'actions' => [['type' => 'auto_reply', 'text' => 'We open at 9']],
    ]);

    incoming('hours?');
    incoming('hours??');

    expect(Message::query()->where('is_automated', true)->count())->toBe(1);
});

it('applies business hour conditions in the workspace timezone', function (string $at, bool $expectSuggestion) {
    app(WorkspaceSettings::class)->update(['business_hours' => ['timezone' => 'Europe/Berlin', 'days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '17:00']]);
    AutomationRule::factory()->create(['conditions' => ['business_hours' => 'outside'], 'actions' => [['type' => 'suggest_reply', 'text' => 'We are closed']]]);

    $this->travelTo($at);
    incoming('hello');

    expect(Conversation::query()->sole()->suggested_reply === 'We are closed')->toBe($expectSuggestion);
})->with([
    'Monday 10:00 Berlin' => ['2026-10-19 08:00:00 UTC', false],
    'Monday 18:30 Berlin' => ['2026-10-19 16:30:00 UTC', true],
    'Saturday noon Berlin' => ['2026-10-24 10:00:00 UTC', true],
]);

it('requires a matching contact tag when configured', function () {
    $vip = Tag::factory()->create();
    AutomationRule::factory()->create(['conditions' => ['tag_ids' => [$vip->id]], 'actions' => [['type' => 'suggest_reply', 'text' => 'VIP reply']]]);
    $tagged = Contact::factory()->hasAttached($vip)->create();

    incoming('hello');
    incoming('hello', $tagged);

    expect(Conversation::query()->whereNotNull('suggested_reply')->sole()->contact_id)->toBe($tagged->id);
});

it('notifies the team without including message content', function () {
    Notification::fake();
    $agent = User::factory()->create();
    AutomationRule::factory()->create(['conditions' => ['keywords' => ['urgent']], 'actions' => [['type' => 'notify', 'user_id' => $agent->id]]]);

    incoming('urgent: my card number is 4111');

    Notification::assertSentTo($agent, AutomationTriggered::class, fn (AutomationTriggered $notification) => ! str_contains(json_encode($notification->toArray($agent)), '4111'));
});

it('ignores outgoing messages', function () {
    AutomationRule::factory()->create(['conditions' => ['keywords' => ['hello']], 'actions' => [['type' => 'suggest_reply', 'text' => 'x']]]);
    $conversation = Conversation::factory()->for($this->device, 'whatsappAccount')->create();
    $message = Message::factory()->for($conversation)->outgoing()->create(['body' => 'hello']);

    app(AutomationEngine::class)->run($message);

    expect(AutomationExecution::query()->count())->toBe(0);
});
