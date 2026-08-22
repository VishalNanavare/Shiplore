<?php

declare(strict_types=1);

namespace App\Libraries\Commission;

/**
 * CommissionService — runtime entry point tying the resolvers to the order/settlement
 * pipeline and the vendor panel.
 *
 * forProduct()/amountForProduct() go through CommissionRuleResolver (Track B's 5-step
 * order: vendor override -> product rule -> category-ancestor-walk rule ->
 * business-type rule -> global default).
 *
 * forVendor() is untouched — still the old CommissionRateRepository/CommissionResolver
 * pair (business-type + global only, no product context). SettlementController's vendor-
 * level summary is out of Track B's scope; $rates/$resolver stay for that one call.
 *
 * @see docs/architecture/31-BUSINESS-TYPE-COMMISSION.md
 */
final class CommissionService
{
    public function __construct(
        private readonly \App\Models\CommissionRateRepository $rates,
        private readonly CommissionResolver $resolver,
        private readonly CommissionRuleResolver $ruleResolver,
    ) {}

    /** @return array{rate:float,level:string} Effective rate for a product (5-step order). */
    public function forProduct(int $productId): array
    {
        $resolved = $this->ruleResolver->resolveForProduct($productId);

        return ['rate' => $resolved['value'], 'level' => $resolved['level']];
    }

    /** @return array{rate:float,level:string} Effective rate for a vendor (business-type + global). */
    public function forVendor(int $vendorId): array
    {
        return $this->resolver->resolve($this->rates->ratesForVendor($vendorId));
    }

    /** Commission amount for a base value at a product's effective rate — percentage or fixed. */
    public function amountForProduct(int $productId, float $base): string
    {
        $resolved = $this->ruleResolver->resolveForProduct($productId);
        if ($resolved['type'] === 'fixed') {
            return number_format(round($resolved['value'], 2), 2, '.', '');
        }

        return $this->resolver->amount($base, $resolved['value']);
    }
}
