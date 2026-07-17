<?php

declare(strict_types=1);

namespace App\Libraries\Payment\Gateways;

use App\Libraries\Payment\AbstractGateway;

/** Razorpay. */
final class RazorpayGateway extends AbstractGateway
{
    public function code(): string
    {
        return 'razorpay';
    }

    public function label(): string
    {
        return 'Razorpay';
    }

    public function fields(): array
    {
        return [
            ['key' => 'key_id',         'label' => 'Key ID',         'secret' => false],
            ['key' => 'key_secret',     'label' => 'Key Secret',     'secret' => true],
            ['key' => 'webhook_secret', 'label' => 'Webhook Secret', 'secret' => true],
        ];
    }
}
