<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * ajax-forms.js must submit to the CLICKED BUTTON's formaction, like a native submit.
 *
 * The interceptor cancels the browser's own submission and replays it with fetch(). A
 * native submission resolves the target as submitter formaction -> form action; the
 * replay used to read only the form's attributes, so every button posted to the form's
 * default action. Two real screens broke, silently:
 *
 *   - admin integrations: "Test saved settings" carries formaction=".../test" on a form
 *     whose action is the SAVE endpoint. Clicking it saved the on-screen settings and
 *     reported "settings saved" — it never tested anything. During a live email outage
 *     this meant the operator's test clicks were doing writes, not tests.
 *   - monline cart: "Remove" carries formaction=".../cart/remove/{id}" on the
 *     quantity-update form. Clicking it updated quantities instead of removing the line.
 *
 * Source assertions, because this repository has no JS test runner: what is pinned is
 * that the resolution order exists in the shipped file, in both copies — public/assets
 * is what the vhost serves, assets/ is the mirror the build copies from. The two have
 * drifted before, which is why both are asserted rather than one.
 */
final class AjaxFormsSubmitterTest extends CIUnitTestCase
{
    private const COPIES = [
        'public/assets/js/ajax-forms.js',
        'assets/js/ajax-forms.js',
    ];

    public function testTheClickedButtonsFormactionWinsInBothCopies(): void
    {
        foreach (self::COPIES as $rel) {
            $src = (string) file_get_contents(ROOTPATH . $rel);

            $this->assertStringContainsString(
                "submitter.getAttribute('formaction')",
                $src,
                $rel . ': the replayed submit must honour the clicked button, or Test buttons save and Remove buttons update',
            );
            $this->assertStringContainsString(
                "submitter.getAttribute('formmethod')",
                $src,
                $rel . ': formmethod is part of the same contract — the cart Remove button relies on it',
            );
            // The property, not the attribute, silently falls back to the form's action
            // even when no override exists — using it would hide the very signal needed.
            $this->assertDoesNotMatchRegularExpression(
                '/submitter\.formAction/',
                $src,
                $rel . ': .formAction is the resolved value, not the attribute — it cannot distinguish an override from the default',
            );
        }
    }

    /** The two copies must not drift — the vhost serves one, edits often land in the other. */
    public function testTheTwoCopiesAreIdentical(): void
    {
        $a = (string) file_get_contents(ROOTPATH . self::COPIES[0]);
        $b = (string) file_get_contents(ROOTPATH . self::COPIES[1]);

        $this->assertSame($a, $b, 'public/assets and assets copies of ajax-forms.js have drifted');
    }
}
