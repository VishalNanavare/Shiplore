<?php

declare(strict_types=1);

namespace App\Libraries\Payment\Gateways;

use App\Libraries\Payment\AbstractGateway;

/** PayU (India) — current default gateway. */
final class PayuGateway extends AbstractGateway
{
    public function code(): string
    {
        return 'payu';
    }

    public function label(): string
    {
        return 'PayU';
    }

    public function fields(): array
    {
        return [
            ['key' => 'merchant_key',  'label' => 'Merchant Key',  'secret' => false],
            ['key' => 'merchant_salt', 'label' => 'Merchant Salt', 'secret' => true],
            ['key' => 'auth_header',   'label' => 'Auth Header',    'secret' => true],
        ];
    }
}
