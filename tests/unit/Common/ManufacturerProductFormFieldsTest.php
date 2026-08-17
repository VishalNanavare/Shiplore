<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The manufacturer product form was missing `tax_class_id` and `unit_id` fields
 * entirely — the repository read both from posted data correctly, but with no
 * matching form field the key was simply never present, so every manufacturer
 * product was created with a NULL tax class and NULL unit of measure.
 *
 * Source assertions against the view template. The fields these check now live in
 * partials/_product_form_body — the manufacturer form was rebuilt to render that
 * shared shell (the same one vendor and admin use) instead of its own bespoke
 * markup, which is what made this screen a thinner lookalike rather than the vendor
 * screen. The assertions follow the markup; the rules they protect are unchanged,
 * and every one of them applies to the vendor form too.
 *
 * Rendered-output coverage of the same screen now also exists in
 * ManufacturerPanelTest, which did not when this file was written.
 */
final class ManufacturerProductFormFieldsTest extends CIUnitTestCase
{
    /** The shell plus the partial it includes — the markup is in one or the other. */
    private function view(): string
    {
        return (string) file_get_contents(APPPATH . 'Views/manufacturer/products/form.php')
            . (string) file_get_contents(APPPATH . 'Views/partials/_product_form_body.php');
    }

    public function testTaxClassFieldExistsAndIteratesTheTaxMaster(): void
    {
        $src = $this->view();

        $this->assertStringContainsString('name="tax_class_id"', $src);
        $this->assertStringContainsString("\$masters['tax']", $src, 'must iterate the same master list AdminProductRepository::formMasters() provides');
    }

    public function testUnitFieldExistsAndIteratesTheUnitsMaster(): void
    {
        $src = $this->view();

        $this->assertStringContainsString('name="unit_id"', $src);
        $this->assertStringContainsString("\$masters['units']", $src);
    }

    /**
     * unit_id must be LOCKED after creation, matching the vendor form's own rule
     * (_product_form_body.php: "Locked after creation — all variants inherit this
     * unit."). Changing the unit of measure on an existing product is the kind of
     * edit that corrupts anything already relying on it.
     */
    public function testUnitOfMeasureIsLockedOnEditNotOnCreate(): void
    {
        $src = $this->view();

        $this->assertMatchesRegularExpression(
            '/name="unit_id" class="form-select" required <\?= \$isEdit \? \'disabled\' : \'\' \?>/',
            $src,
            'the unit select must be disabled once $isEdit is true, not before',
        );
        // A disabled <select> does not POST its value — the hidden input is what
        // actually carries it through on an edit submit.
        $this->assertMatchesRegularExpression(
            '/<\?php if \(\$isEdit\): \?>\s*<input type="hidden" name="unit_id"/',
            $src,
            'a hidden input must carry the locked unit_id through on edit, or a disabled select silently drops it from the POST',
        );
    }

    /** Tax class must stay editable at every stage — only the unit of measure locks. */
    public function testTaxClassIsNeverLocked(): void
    {
        $src = $this->view();
        $start = strpos($src, 'name="tax_class_id"');
        $this->assertNotFalse($start);
        $fieldMarkup = substr($src, $start, (int) strpos($src, '</select>', $start) - $start);

        $this->assertStringNotContainsString('disabled', $fieldMarkup, 'tax class must remain editable on every product, unlike unit of measure');
    }
}
