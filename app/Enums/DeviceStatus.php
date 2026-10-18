<?php

namespace App\Enums;

enum DeviceStatus: string
{
    case Created = 'created';
    case Connecting = 'connecting';
    case QrReady = 'qr_ready';
    case Connected = 'connected';
    case Reconnecting = 'reconnecting';
    case Disconnected = 'disconnected';
    case LoggedOut = 'logged_out';
    case Failed = 'failed';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Created => [self::Connecting, self::Failed],
            self::Connecting => [self::QrReady, self::Connected, self::Reconnecting, self::Disconnected, self::LoggedOut, self::Failed],
            self::QrReady => [self::QrReady, self::Connecting, self::Connected, self::Disconnected, self::LoggedOut, self::Failed],
            self::Connected => [self::Connecting, self::Reconnecting, self::Disconnected, self::LoggedOut, self::Failed],
            self::Reconnecting => [self::Connecting, self::QrReady, self::Connected, self::Disconnected, self::LoggedOut, self::Failed],
            self::Disconnected => [self::Connecting, self::LoggedOut],
            self::LoggedOut => [self::Connecting],
            self::Failed => [self::Connecting, self::LoggedOut],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * Whether the gateway currently owns (or is establishing) a socket for this session.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Connecting, self::QrReady, self::Connected, self::Reconnecting], true);
    }

    public function canConnect(): bool
    {
        return in_array($this, [self::Created, self::Disconnected, self::LoggedOut, self::Failed], true);
    }
}
