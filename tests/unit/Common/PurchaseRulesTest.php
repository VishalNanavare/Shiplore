<?php

declare(strict_types=1);

use App\Libraries\Catalog\PurchaseRules;
use CodeIgniter\Test\CIUnitTestCase as TestCase;

/** P5 — pure purchase-rule validation (min/max/step + payment restriction). */
final class PurchaseRulesTest extends TestCase
{
    public function testNoRulesAllowsAnyPositiveQty(): void
    {
        $this->assertTrue(PurchaseRules::validate(7, [])['ok']);
        $this->assertFalse(PurchaseRules::validate(0, [])['ok']);
    }

    public function testMinAndMax(): void
    {
        $rules = ['min_purchase_qty' => 2, 'max_purchase_qty' => 10];
        $this->assertFalse(PurchaseRules::validate(1, $rules)['ok']);
        $this->assertTrue(PurchaseRules::validate(2, $rules)['ok']);
        $this->assertTrue(PurchaseRules::validate(10, $rules)['ok']);
        $this->assertFalse(PurchaseRules::validate(11, $rules)['ok']);
    }

    public function testStepMultiples(): void
    {
        $rules = ['qty_step' => 5];
        $this->assertTrue(PurchaseRules::validate(5, $rules)['ok']);
        $this->assertTrue(PurchaseRules::validate(10, $rules)['ok']);
        $this->assertFalse(PurchaseRules::validate(7, $rules)['ok']);
    }

    public function testStepRelativeToMin(): void
    {
        // must buy 6, 8, 10... (min 6, step 2)
        $rules = ['min_purchase_qty' => 6, 'qty_step' => 2];
        $this->assertTrue(PurchaseRules::validate(6, $rules)['ok']);
        $this->assertTrue(PurchaseRules::validate(8, $rules)['ok']);
        $this->assertFalse(PurchaseRules::validate(7, $rules)['ok']);
    }

    /**
     * qty_step=1 — "whole units only" — is the schema default
     * (products.qty_step DECIMAL(12,3) NOT NULL DEFAULT 1.000) for every product that
     * hasn't had a custom step configured. It must still reject a fractional quantity
     * submitted via a raw request that bypasses the HTML5 step="1" constraint.
     */
    public function testStepOfOneRejectsFractionalQuantities(): void
    {
        $rules = ['qty_step' => 1];
        $this->assertTrue(PurchaseRules::validate(5, $rules)['ok']);
        $this->assertFalse(PurchaseRules::validate(5.37, $rules)['ok']);
    }

    /** A sub-1 step (weight-based wholesale, e.g. quarter-kilo packs) must also be enforced. */
    public function testFractionalStepIsEnforced(): void
    {
        $rules = ['qty_step' => 0.25];
        $this->assertTrue(PurchaseRules::validate(0.5, $rules)['ok']);
        $this->assertFalse(PurchaseRules::validate(0.6, $rules)['ok']);
    }

    /** qty_step=0 (or absent) still means "no constraint" — the documented contract. */
    public function testStepOfZeroMeansNoConstraint(): void
    {
        $this->assertTrue(PurchaseRules::validate(5.37, ['qty_step' => 0])['ok']);
    }

    public function testPaymentRestriction(): void
    {
        $this->assertTrue(PurchaseRules::paymentAllowed('cod', 'both'));
        $this->assertTrue(PurchaseRules::paymentAllowed('upi', 'both'));
        $this->assertFalse(PurchaseRules::paymentAllowed('cod', 'online'));
        $this->assertTrue(PurchaseRules::paymentAllowed('upi', 'online'));
        $this->assertTrue(PurchaseRules::paymentAllowed('cod', 'cod'));
        $this->assertFalse(PurchaseRules::paymentAllowed('upi', 'cod'));
    }
}
