<?php

use App\Jobs\DownloadIncomingAttachment;
use App\Models\Message;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['messaging.gateway.url' => 'http://gateway.test', 'messaging.gateway.token' => 'token']);
    Http::preventStrayRequests();
    Storage::fake('local');
});

it('stores gateway media privately and deletes the gateway copy', function () {
    $message = Message::factory()->create(['type' => 'document']);
    Http::fake([
        'http://gateway.test/v1/media/abc' => Http::sequence()
            ->push('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf', 'X-Media-File-Name' => rawurlencode('../../invoice.pdf')])
            ->push('', 204),
    ]);

    app()->call([new DownloadIncomingAttachment($message, 'abc'), 'handle']);

    $attachment = $message->attachments()->sole();
    expect($attachment)
        ->mime_type->toBe('application/pdf')
        ->original_name->toBe('invoice.pdf');
    Storage::disk('local')->assertExists($attachment->path);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === 'http://gateway.test/v1/media/abc');
});

it('refuses media types outside the allow-list', function () {
    $message = Message::factory()->create();
    Http::fake([
        'http://gateway.test/v1/media/abc' => Http::sequence()
            ->push('<svg onload=alert(1)>', 200, ['Content-Type' => 'image/svg+xml'])
            ->push('', 204),
    ]);

    app()->call([new DownloadIncomingAttachment($message, 'abc'), 'handle']);

    expect($message->attachments()->count())->toBe(0)
        ->and($message->fresh()->metadata['media_error'])->toBe('The attachment type or size is not supported.');
});

it('records an error when the media expired on the gateway', function () {
    $message = Message::factory()->create();
    Http::fake(['http://gateway.test/v1/media/abc' => Http::response(['error' => ['code' => 'media_not_found']], 404)]);

    app()->call([new DownloadIncomingAttachment($message, 'abc'), 'handle']);

    expect($message->fresh()->metadata['media_error'])->toBe('The attachment expired on the gateway before it could be saved.');
});
