<?php

declare(strict_types=1);

namespace App\Libraries\Catalog;

/**
 * VariantCombinator — pure Cartesian-product engine for the variant builder.
 * Given the chosen value-ids per variant-defining attribute, it produces every
 * combination (e.g. Size{S,M} × Color{Red,Black} → 4 variants). No DB access,
 * so it is fully unit-testable; the repository persists what this returns.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md (product module, section 7)
 */
final class VariantCombinator
{
    /**
     * @param array<int,list<int>> $selections attribute_id => list of value_ids
     * @return list<array<int,int>>            each row = attribute_id => value_id
     */
    public static function combine(array $selections): array
    {
        // drop attributes with no chosen values; keep deterministic attribute order
        $selections = array_filter($selections, static fn ($vals) => is_array($vals) && $vals !== []);
        if ($selections === []) {
            return [];
        }
        ksort($selections);

        $combos = [[]];
        foreach ($selections as $attrId => $valueIds) {
            $valueIds = array_values(array_unique(array_map('intval', $valueIds)));
            $next = [];
            foreach ($combos as $combo) {
                foreach ($valueIds as $valueId) {
                    $next[] = $combo + [(int) $attrId => $valueId];
                }
            }
            $combos = $next;
        }

        return $combos;
    }

    /**
     * Stable, human-readable signature for a combination so duplicates can be
     * detected ("1:5|2:9"). Order-independent.
     * @param array<int,int> $combo
     */
    public static function signature(array $combo): string
    {
        ksort($combo);
        $parts = [];
        foreach ($combo as $attrId => $valueId) {
            $parts[] = $attrId . ':' . $valueId;
        }

        return implode('|', $parts);
    }

    /**
     * Build a SKU suffix from value codes/labels, e.g. ['Red','M'] → "RED-M".
     * @param list<string> $labels
     */
    public static function skuSuffix(array $labels): string
    {
        $parts = [];
        foreach ($labels as $label) {
            $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) $label));
            if ($code !== '') {
                $parts[] = $code;
            }
        }

        return implode('-', $parts);
    }
}
