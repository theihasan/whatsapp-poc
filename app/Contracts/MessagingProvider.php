<?php

namespace App\Contracts;

use App\DTOs\OutgoingMessage;
use App\DTOs\ProviderCapabilities;
use App\DTOs\ProviderHealth;
use App\DTOs\SendResult;
use App\Enums\MessagingProviderName;
use App\Exceptions\ProviderUnavailableException;
use App\Models\WhatsAppAccount;

/**
 * Boundary between Laravel and a WhatsApp transport. Connection state changes and inbound
 * traffic never come back as return values; they arrive later as normalized gateway events.
 */
interface MessagingProvider
{
    public function name(): MessagingProviderName;

    public function capabilities(): ProviderCapabilities;

    /**
     * Start (or resume) the session. QR / connected states arrive as events.
     *
     * @throws ProviderUnavailableException
     */
    public function connect(WhatsAppAccount $account): void;

    /**
     * Close the socket but keep stored credentials so the session can be resumed.
     *
     * @throws ProviderUnavailableException
     */
    public function disconnect(WhatsAppAccount $account): void;

    /**
     * Log the linked device out and delete its stored credentials.
     *
     * @throws ProviderUnavailableException
     */
    public function revoke(WhatsAppAccount $account): void;

    /**
     * Hand a message to the transport. Never throws for delivery outcomes; the result says
     * whether it was accepted, rejected, or the outcome is unknown.
     *
     * @throws ProviderUnavailableException when the request certainly never reached the transport
     */
    public function sendMessage(WhatsAppAccount $account, OutgoingMessage $message): SendResult;

    public function health(): ProviderHealth;
}
