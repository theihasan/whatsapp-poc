<?php

namespace Database\Factories;

use App\Enums\ContactSource;
use App\Models\Contact;
use App\Support\FakePhone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $phoneNumber = FakePhone::number();

        return [
            'name' => fake()->name(),
            'phone_number' => $phoneNumber,
            'wa_jid' => FakePhone::jid($phoneNumber),
            'source' => ContactSource::Manual,
        ];
    }
}
