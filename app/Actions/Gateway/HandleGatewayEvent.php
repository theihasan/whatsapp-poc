<?php

namespace App\Actions\Gateway;

use App\Actions\Contacts\LinkContactIdentity;
use App\Actions\Devices\ApplyDeviceStatus;
use App\Actions\Messages\MarkOutgoingMessageFailed;
use App\Actions\Messages\ProcessIncomingMessage;
use App\Actions\Messages\UpdateMessageDeliveryStatus;
use App\Enums\DeviceStatus;
use App\Models\GatewayEvent;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\DeviceQrStore;
use Illuminate\Support\Arr;

/**
 * Routes a stored gateway event to the action that owns it. Returns false when the event
 * was valid but had no effect (unknown session, stale transition, duplicate, unknown type).
 */
class HandleGatewayEvent
{
    public function __construct(
        private ApplyDeviceStatus $applyDeviceStatus,
        private DeviceQrStore $qrStore,
        private ProcessIncomingMessage $processIncomingMessage,
        private UpdateMessageDeliveryStatus $updateMessageDeliveryStatus,
        private MarkOutgoingMessageFailed $markOutgoingMessageFailed,
        private LinkContactIdentity $linkContactIdentity,
    ) {}

    public function handle(GatewayEvent $event): bool
    {
        $account = WhatsAppAccount::query()->find($event->session_id);

        if ($account === null) {
            return false;
        }

        $payload = $event->payload;
        $reason = $this->string($payload, 'reason');

        return match ($event->type) {
            'session.connecting' => $this->applyDeviceStatus->handle($account, DeviceStatus::Connecting, $reason),
            'session.qr.updated' => $this->qrUpdated($account, $payload),
            'session.connected' => $this->applyDeviceStatus->handle($account, DeviceStatus::Connected, null, [
                'phone_number' => $this->string($payload, 'phoneNumber'),
                'wa_jid' => $this->string($payload, 'jid'),
                'display_name' => $this->string($payload, 'displayName'),
            ]),
            'session.reconnecting' => $this->applyDeviceStatus->handle($account, DeviceStatus::Reconnecting, $reason),
            'session.disconnected' => $this->applyDeviceStatus->handle($account, DeviceStatus::Disconnected, $reason),
            'session.logged_out' => $this->applyDeviceStatus->handle($account, DeviceStatus::LoggedOut, $reason),
            'session.failed' => $this->applyDeviceStatus->handle($account, DeviceStatus::Failed, $reason),
            'message.received' => $this->processIncomingMessage->handle($account, $payload) !== null,
            'message.status.updated' => $this->updateMessageDeliveryStatus->handle($account, $payload),
            'message.failed' => $this->markOutgoingMessageFailed->handle($account, $payload),
            'contact.identified' => $this->identify($payload),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function qrUpdated(WhatsAppAccount $account, array $payload): bool
    {
        $qr = $this->string($payload, 'qr');

        if ($qr === null || ! $this->applyDeviceStatus->handle($account, DeviceStatus::QrReady)) {
            return false;
        }

        $this->qrStore->put($account, $qr, (int) Arr::get($payload, 'expiresInSeconds', config('messaging.qr_ttl')));

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function identify(array $payload): bool
    {
        $lid = $this->string($payload, 'lid');
        $jid = $this->string($payload, 'jid');
        $phoneNumber = $this->string($payload, 'phoneNumber');

        if ($lid === null || $jid === null || $phoneNumber === null) {
            return false;
        }

        return $this->linkContactIdentity->handle($lid, $jid, $phoneNumber) !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function string(array $payload, string $key): ?string
    {
        $value = Arr::get($payload, $key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
