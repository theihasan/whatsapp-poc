<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ContactConsent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactConsent>
 */
class ContactConsentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'scope' => 'marketing',
            'source' => 'web_form',
            'evidence' => 'Signup form submission #'.fake()->numerify('####'),
            'consented_at' => now()->subDays(3),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => ['revoked_at' => now()]);
    }
}
