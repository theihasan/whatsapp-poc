<?php

namespace App\Http\Requests\Contacts;

use App\Models\Contact;
use App\Rules\E164PhoneNumber;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ContactRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Contact|null $contact */
        $contact = $this->route('contact');

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => [$contact === null ? 'required' : 'sometimes', 'string', 'max:32', new E164PhoneNumber],
            'notes' => ['nullable', 'string', 'max:5000'],
            'tags' => ['array', 'max:20'],
            'tags.*' => ['string', 'max:64', 'distinct'],
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $normalized = PhoneNumber::normalize((string) $this->input('phone_number', ''));

                if ($normalized === null) {
                    return;
                }

                /** @var Contact|null $contact */
                $contact = $this->route('contact');
                $exists = Contact::query()
                    ->where(fn ($query) => $query->where('phone_number', $normalized)->orWhere('wa_jid', PhoneNumber::toJid($normalized)))
                    ->when($contact, fn ($query) => $query->whereKeyNot($contact->id))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('phone_number', 'A contact with this number already exists.');
                }
            },
        ];
    }
}
