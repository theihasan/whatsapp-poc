<?php

namespace App\Jobs;

use App\Events\MessageStatusUpdated;
use App\Exceptions\ProviderUnavailableException;
use App\Models\Message;
use App\Services\Messaging\GatewayClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Moves incoming media from the gateway's temporary store to the private attachment
 * disk, then tells the gateway to delete its copy.
 */
class DownloadIncomingAttachment implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300, 900];

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'application/pdf' => 'pdf', 'text/plain' => 'txt',
        'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a',
    ];

    public function __construct(public Message $message, public string $mediaId)
    {
        $this->onQueue('messages');
    }

    public function handle(GatewayClient $gateway): void
    {
        if ($this->message->attachments()->exists()) {
            return;
        }

        try {
            $response = $gateway->request()->accept('*/*')->timeout(60)->get("/v1/media/{$this->mediaId}");
        } catch (ConnectionException $exception) {
            throw new ProviderUnavailableException('Gateway unreachable while downloading media.', previous: $exception);
        }

        if ($response->status() === 404) {
            $this->recordError('The attachment expired on the gateway before it could be saved.');

            return;
        }

        $response->throw();

        $mimeType = Str::before((string) $response->header('Content-Type'), ';');
        $extension = self::ALLOWED_MIME_TYPES[$mimeType] ?? null;
        $maxBytes = (int) config('messaging.attachments.max_kilobytes') * 1024;

        if ($extension === null || strlen($response->body()) > $maxBytes) {
            $this->recordError('The attachment type or size is not supported.');
            $gateway->request()->delete("/v1/media/{$this->mediaId}");

            return;
        }

        $disk = (string) config('messaging.attachments.disk');
        $path = 'attachments/'.now()->format('Y/m').'/'.Str::ulid().'.'.$extension;
        Storage::disk($disk)->put($path, $response->body());

        $fileName = rawurldecode((string) $response->header('X-Media-File-Name'));

        $this->message->attachments()->create([
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $mimeType,
            'size' => strlen($response->body()),
            'original_name' => $fileName === '' ? null : mb_substr(basename($fileName), 0, 255),
        ]);

        $gateway->request()->delete("/v1/media/{$this->mediaId}");
        MessageStatusUpdated::dispatch($this->message);
    }

    public function failed(?Throwable $exception): void
    {
        $this->recordError('The attachment could not be downloaded.');
    }

    private function recordError(string $error): void
    {
        $this->message->update(['metadata' => [...($this->message->metadata ?? []), 'media_error' => $error]]);
        MessageStatusUpdated::dispatch($this->message);
    }
}
