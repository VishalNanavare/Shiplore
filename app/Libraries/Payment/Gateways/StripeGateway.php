<?php

declare(strict_types=1);

namespace App\Libraries\Payment\Gateways;

use App\Libraries\Payment\AbstractGateway;

/** Stripe. */
final class StripeGateway extends AbstractGateway
{
    public function code(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Stripe';
    }

    public function fields(): array
    {
        return [
            ['key' => 'publishable_key', 'label' => 'Publishable Key', 'secret' => false],
            ['key' => 'secret_key',      'label' => 'Secret Key',      'secret' => true],
            ['key' => 'webhook_secret',  'label' => 'Webhook Secret',  'secret' => true],
        ];
    }
}
