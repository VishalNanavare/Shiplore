<?php

declare(strict_types=1);

use App\Libraries\Commission\CommissionResolver;
use App\Libraries\Commission\CommissionRuleResolver;
use App\Libraries\Commission\CommissionService;
use App\Models\CommissionRateRepository;

final class CommissionServiceTest extends \PHPUnit\Framework\TestCase
{
    public function testForProductDelegatesToTheNewRuleResolver(): void
    {
        $ruleResolver = new class extends CommissionRuleResolver {
            public function __construct() {}
            public function resolveForProduct(int $productId): array
            {
                return ['type' => 'percentage', 'value' => 7.5, 'level' => 'rule:category'];
            }
        };
        $service = new CommissionService(new CommissionRateRepository(), new CommissionResolver(), $ruleResolver);

        $result = $service->forProduct(42);

        $this->assertSame(7.5, $result['rate']);
        $this->assertSame('rule:category', $result['level']);
    }

    public function testAmountForProductHandlesAPercentageWinner(): void
    {
        $ruleResolver = new class extends CommissionRuleResolver {
            public function __construct() {}
            public function resolveForProduct(int $productId): array
            {
                return ['type' => 'percentage', 'value' => 10.0, 'level' => 'plan:global'];
            }
        };
        $service = new CommissionService(new CommissionRateRepository(), new CommissionResolver(), $ruleResolver);

        $this->assertSame('250.00', $service->amountForProduct(1, 2500.0));
    }

    public function testAmountForProductHandlesAFixedWinner(): void
    {
        $ruleResolver = new class extends CommissionRuleResolver {
            public function __construct() {}
            public function resolveForProduct(int $productId): array
            {
                return ['type' => 'fixed', 'value' => 25.0, 'level' => 'rule:product'];
            }
        };
        $service = new CommissionService(new CommissionRateRepository(), new CommissionResolver(), $ruleResolver);

        // Fixed amount, independent of base — not a percentage of 2500.
        $this->assertSame('25.00', $service->amountForProduct(1, 2500.0));
    }
}
