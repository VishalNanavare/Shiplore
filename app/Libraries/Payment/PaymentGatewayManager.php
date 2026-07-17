<?php

declare(strict_types=1);

namespace App\Libraries\Payment;

use App\Libraries\Payment\Gateways\PayuGateway;
use App\Libraries\Payment\Gateways\RazorpayGateway;
use App\Libraries\Payment\Gateways\StripeGateway;

/**
 * PaymentGatewayManager — registry of supported gateway drivers. The whole
 * payment system (admin panel + checkout) goes through this: it lists the
 * providers that can be configured, resolves a driver by code, and validates /
 * tests a configuration. To support a new gateway, implement
 * PaymentGatewayInterface and add it to DRIVERS — nothing else changes.
 *
 * @see docs/architecture/12-PAYMENT-ARCHITECTURE.md
 */
final class PaymentGatewayManager
{
    /** @var list<class-string<PaymentGatewayInterface>> */
    private const DRIVERS = [
        PayuGateway::class,
        RazorpayGateway::class,
        StripeGateway::class,
    ];

    /** @var array<string,PaymentGatewayInterface> */
    private array $byCode = [];

    public function __construct()
    {
        foreach (self::DRIVERS as $class) {
            $driver = new $class();
            $this->byCode[$driver->code()] = $driver;
        }
    }

    /** @return array<string,string> code => label */
    public function availableProviders(): array
    {
        $out = [];
        foreach ($this->byCode as $code => $driver) {
            $out[$code] = $driver->label();
        }

        return $out;
    }

    public function driver(string $code): ?PaymentGatewayInterface
    {
        return $this->byCode[$code] ?? null;
    }

    /** @return list<array{key:string,label:string,secret:bool}> */
    public function fields(string $code): array
    {
        return $this->driver($code)?->fields() ?? [];
    }

    /**
     * @param array<string,mixed> $config
     * @return array{ok:bool,message:string}
     */
    public function test(string $code, array $config): array
    {
        $driver = $this->driver($code);
        if ($driver === null) {
            return ['ok' => false, 'message' => 'Unknown payment provider: ' . $code];
        }

        return $driver->testConnection($config);
    }
}
