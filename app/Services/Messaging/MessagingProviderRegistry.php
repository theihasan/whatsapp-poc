<?php

namespace App\Services\Messaging;

use App\Contracts\MessagingProvider;
use App\Enums\MessagingProviderName;
use App\Exceptions\ProviderNotEnabledException;
use App\Models\WhatsAppAccount;
use Illuminate\Contracts\Container\Container;

class MessagingProviderRegistry
{
    public function __construct(private Container $container) {}

    public function for(WhatsAppAccount $account): MessagingProvider
    {
        return $this->resolve($account->provider);
    }

    /**
     * @throws ProviderNotEnabledException
     */
    public function resolve(MessagingProviderName $name): MessagingProvider
    {
        if (! $this->isEnabled($name)) {
            throw new ProviderNotEnabledException("The {$name->value} provider is not enabled.");
        }

        return match ($name) {
            MessagingProviderName::Mock => $this->container->make(MockMessagingProvider::class),
            MessagingProviderName::UnofficialWeb => $this->container->make(UnofficialWebProvider::class),
            MessagingProviderName::OfficialCloud => $this->container->make(OfficialCloudProvider::class),
        };
    }

    public function isEnabled(MessagingProviderName $name): bool
    {
        return match ($name) {
            MessagingProviderName::Mock => true,
            MessagingProviderName::UnofficialWeb => (bool) config('messaging.unofficial_enabled'),
            MessagingProviderName::OfficialCloud => filled(config('messaging.official.access_token'))
                && filled(config('messaging.official.phone_number_id')),
        };
    }

    /**
     * @return list<MessagingProviderName>
     */
    public function enabledProviders(): array
    {
        return array_values(array_filter(
            MessagingProviderName::cases(),
            fn (MessagingProviderName $name): bool => $this->isEnabled($name),
        ));
    }

    public function defaultProvider(): MessagingProviderName
    {
        return MessagingProviderName::from((string) config('messaging.default_provider'));
    }
}
