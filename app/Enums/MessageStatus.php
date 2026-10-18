<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case GatewayAccepted = 'gateway_accepted';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case Received = 'received';

    /**
     * Position on the forward-only delivery ladder. Statuses outside the ladder return null.
     */
    public function rank(): ?int
    {
        return match ($this) {
            self::Queued => 0,
            self::Processing => 1,
            self::GatewayAccepted => 2,
            self::Sent => 3,
            self::Delivered => 4,
            self::Read => 5,
            default => null,
        };
    }

    /**
     * Whether a protocol status update may move a message from this status to the given one.
     * Delivery evidence always wins over failed/unknown; the ladder never moves backwards.
     */
    public function canAdvanceTo(self $next): bool
    {
        if ($next->rank() === null || $this === self::Received) {
            return false;
        }

        if ($this->rank() === null) {
            return $next->rank() >= self::Sent->rank();
        }

        return $next->rank() > $this->rank();
    }

    public function isRetryable(): bool
    {
        return in_array($this, [self::Failed, self::Unknown], true);
    }
}
