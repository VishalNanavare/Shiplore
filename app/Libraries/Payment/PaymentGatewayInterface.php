<?php

declare(strict_types=1);

namespace App\Libraries\Payment;

/**
 * PaymentGatewayInterface — contract every payment-gateway driver implements.
 * Adding a new gateway = add one class implementing this interface and register
 * it in PaymentGatewayManager::DRIVERS. The admin panel and the payment flow
 * then work with it automatically.
 *
 * @see docs/architecture/12-PAYMENT-ARCHITECTURE.md
 */
interface PaymentGatewayInterface
{
    /** Stable provider code (matches gateway_accounts.provider), e.g. 'payu'. */
    public function code(): string;

    /** Human label, e.g. 'PayU'. */
    public function label(): string;

    /**
     * Config keys this gateway needs (used to render the form + validate).
     * @return list<array{key:string,label:string,secret:bool}>
     */
    public function fields(): array;

    /**
     * Validate / probe a config. Pure where possible; any network probe must be
     * wrapped so it returns a result rather than throwing.
     *
     * @param array<string,mixed> $config
     * @return array{ok:bool,message:string}
     */
    public function testConnection(array $config): array;
}
