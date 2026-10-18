<?php

namespace App\Http\Controllers\Messages;

use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Stream a private attachment to an authenticated user. Raster images render inline;
     * everything else downloads.
     */
    public function show(MessageAttachment $attachment): StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        $inline = in_array($attachment->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true);
        $name = $attachment->original_name ?? 'attachment';

        return $disk->response($attachment->path, $name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ], $inline ? 'inline' : 'attachment');
    }
}
