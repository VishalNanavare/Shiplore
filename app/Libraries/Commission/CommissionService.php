<?php

declare(strict_types=1);

namespace App\Libraries\Commission;

/**
 * CommissionService — runtime entry point that ties the rate repository to the
 * resolver. Used by the order/settlement pipeline and the vendor panel to get a
 * vendor's / product's effective commission rate + amount.
 *
 * @see docs/architecture/31-BUSINESS-TYPE-COMMISSION.md
 */
final class CommissionService
{
    public function __construct(
        private readonly \App\Models\CommissionRateRepository $rates,
        private readonly CommissionResolver $resolver,
    ) {}

    /** @return array{rate:float,level:string} Effective rate for a product (full hierarchy). */
    public function forProduct(int $productId): array
    {
        return $this->resolver->resolve($this->rates->ratesForProduct($productId));
    }

    /** @return array{rate:float,level:string} Effective rate for a vendor (business-type + global). */
    public function forVendor(int $vendorId): array
    {
        return $this->resolver->resolve($this->rates->ratesForVendor($vendorId));
    }

    /** Commission amount for a base value at a product's effective rate. */
    public function amountForProduct(int $productId, float $base): string
    {
        return $this->resolver->amount($base, $this->forProduct($productId)['rate']);
    }
}
