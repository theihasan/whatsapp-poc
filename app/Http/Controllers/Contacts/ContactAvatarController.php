<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactAvatarController extends Controller
{
    /**
     * Stream a contact's stored WhatsApp profile picture to a signed-in user.
     */
    public function __invoke(Contact $contact): StreamedResponse
    {
        $disk = Storage::disk((string) config('messaging.attachments.disk'));

        abort_if($contact->avatar_path === null || ! $disk->exists($contact->avatar_path), 404);

        return $disk->response($contact->avatar_path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ], 'inline');
    }
}
