<?php

namespace Database\Seeders;

use App\Enums\ContactSource;
use App\Enums\DeviceStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\TemplateStatus;
use App\Models\AutomationRule;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MessageTemplate;
use App\Models\Tag;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Support\FakePhone;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Privacy-safe demo workspace: fictional 555-01xx numbers only, mock provider only.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $owner = User::factory()->create([
            'name' => 'Demo Owner',
            'email' => 'demo@example.com',
        ]);
        $agent = User::factory()->create([
            'name' => 'Demo Agent',
            'email' => 'agent@example.com',
        ]);

        $device = WhatsAppAccount::factory()->connected()->create([
            'name' => 'Support line (mock)',
            'display_name' => 'Demo Support',
            'status' => DeviceStatus::Connected,
        ]);
        WhatsAppAccount::factory()->create(['name' => 'Sales line (mock)']);

        $tags = collect(['vip', 'lead', 'billing'])->map(fn (string $name) => Tag::query()->create(['name' => $name]));

        $threads = [
            ['Ava Martinez', ['Hi! Is my order #4821 shipped yet?', 'It left our warehouse this morning — tracking is on its way.', 'Great, thank you!']],
            ['Noah Kim', ['Do you have the blue model in stock?', 'We do, in sizes M and L.']],
            ['Liam Chen', ['Can I change my delivery address?']],
            ['Mia Johnson', ['Thanks for the quick help yesterday 🙏', 'Anytime!']],
            ['Ethan Brown', ['What are your opening hours on Sunday?']],
        ];

        foreach ($threads as $index => [$name, $lines]) {
            $phone = FakePhone::number();
            $contact = Contact::query()->create([
                'name' => $name,
                'phone_number' => $phone,
                'wa_jid' => FakePhone::jid($phone),
                'source' => ContactSource::IncomingMessage,
                'last_activity_at' => now()->subHours($index),
            ]);
            $contact->tags()->attach($tags[$index % 3]->id);

            $conversation = Conversation::query()->create([
                'whatsapp_account_id' => $device->id,
                'contact_id' => $contact->id,
                'assigned_user_id' => $index === 0 ? $agent->id : null,
                'unread_count' => count($lines) % 2,
            ]);

            foreach ($lines as $position => $body) {
                $this->seedMessage($conversation, $body, $position % 2 === 1, now()->subHours($index)->subMinutes((count($lines) - $position) * 7));
            }
        }

        MessageTemplate::query()->create([
            'name' => 'Greeting',
            'category' => 'General',
            'body' => 'Hi {{contact_name}}, thanks for reaching out to {{company_name}}! How can we help?',
            'status' => TemplateStatus::Active,
        ]);
        MessageTemplate::query()->create([
            'name' => 'Opening hours',
            'category' => 'Support',
            'body' => 'Hi {{contact_name}}, we are open Monday to Saturday, 9:00–18:00.',
            'status' => TemplateStatus::Active,
        ]);

        AutomationRule::query()->create([
            'name' => 'Suggest opening hours reply',
            'priority' => 10,
            'conditions' => ['keywords' => ['hours', 'open']],
            'actions' => [['type' => 'suggest_reply', 'text' => 'We are open Monday to Saturday, 9:00–18:00.']],
            'max_runs_per_conversation_per_hour' => 1,
        ]);

        $this->command?->info("Demo login: {$owner->email} / password (mock provider only)");
    }

    private function seedMessage(Conversation $conversation, string $body, bool $outgoing, \DateTimeInterface $at): void
    {
        $message = $conversation->messages()->make([
            'whatsapp_account_id' => $conversation->whatsapp_account_id,
            'contact_id' => $conversation->contact_id,
            'direction' => $outgoing ? MessageDirection::Outgoing : MessageDirection::Incoming,
            'type' => MessageType::Text,
            'body' => $body,
            'external_id' => 'SEED-'.Str::upper(Str::random(12)),
            'idempotency_key' => $outgoing ? (string) Str::ulid($at) : null,
            'status' => $outgoing ? MessageStatus::Read : MessageStatus::Received,
            'sent_at' => $outgoing ? $at : null,
            'delivered_at' => $outgoing ? $at : null,
            'read_at' => $outgoing ? $at : null,
        ]);
        $message->id = (string) Str::ulid($at);
        $message->created_at = $at;
        $message->updated_at = $at;
        $message->save();

        $conversation->update([
            'last_message_at' => $at,
            'last_message_preview' => Str::limit($body, 120),
        ]);
    }
}
