<?php

declare(strict_types=1);

namespace App\Libraries\Ai;

/**
 * AiProductAssistant — AI-assisted product authoring (auto-SEO, tag suggestions,
 * description generation). Future-ready: when a real provider/key is configured
 * via admin integrations it would call the model; until then a DETERMINISTIC
 * dev-stub composes sensible text from the product's own fields — the same
 * "seam + dev fallback" pattern as the other integrations.
 *
 * The stub*() methods are pure (no DB, no randomness) so they are unit-testable.
 *
 * @see docs/architecture/42-DEVELOPMENT-ROADMAP.md (AI & automation), 24-ADMIN-PANEL.md §30
 */
final class AiProductAssistant
{
    /** Produce a suggestion of the given kind for a product field-set. @param array<string,mixed> $p @return array<string,mixed> */
    public function suggest(string $kind, array $p): array
    {
        $title    = trim((string) ($p['title'] ?? ''));
        $category = trim((string) ($p['category'] ?? ''));
        $brand    = trim((string) ($p['brand'] ?? ''));

        // A real provider would be resolved + called here; absent one, use the stub.
        return match ($kind) {
            'seo'         => self::stubSeo($title, $category, $brand),
            'tags'        => ['tags' => self::stubTags($title, $category)],
            'description' => ['description' => self::stubDescription($title, $category, $brand)],
            default       => [],
        };
    }

    /** @return array{meta_title:string, meta_description:string, meta_keywords:string} */
    public static function stubSeo(string $title, string $category, string $brand): array
    {
        $t  = $title !== '' ? $title : 'Product';
        $mt = $t . ($brand !== '' ? ' by ' . $brand : '') . ' | Buy Online';
        $md = 'Shop ' . $t . ($category !== '' ? ' in ' . $category : '') . ($brand !== '' ? ' from ' . $brand : '')
            . '. Best price, genuine quality, fast delivery and easy returns.';
        $kw = array_values(array_unique(array_filter(array_merge(
            self::words($t),
            $category !== '' ? [strtolower($category)] : [],
            $brand !== '' ? [strtolower($brand)] : [],
            ['buy online', 'best price'],
        ))));

        return [
            'meta_title'       => mb_substr($mt, 0, 191),
            'meta_description' => mb_substr($md, 0, 300),
            'meta_keywords'    => mb_substr(implode(', ', $kw), 0, 255),
        ];
    }

    /** @return list<string> */
    public static function stubTags(string $title, string $category): array
    {
        $tags = self::words($title);
        if ($category !== '') {
            $tags[] = strtolower($category);
        }

        return array_values(array_unique(array_slice($tags, 0, 10)));
    }

    public static function stubDescription(string $title, string $category, string $brand): string
    {
        $t = $title !== '' ? $title : 'This product';
        $s1 = 'The ' . $t . ($brand !== '' ? ' from ' . $brand : '') . ' is designed to deliver dependable everyday performance'
            . ($category !== '' ? ' in the ' . $category . ' category' : '') . '.';
        $s2 = 'Crafted with quality materials and attention to detail, it offers great value for money.';
        $s3 = 'Order now for fast delivery, secure payment and a hassle-free return policy.';

        return $s1 . ' ' . $s2 . ' ' . $s3;
    }

    /** @return list<string> meaningful lowercase words (length > 2, de-noised) */
    private static function words(string $text): array
    {
        $stop = ['the', 'and', 'for', 'with', 'pro', 'new'];
        $parts = preg_split('/[^a-z0-9]+/i', strtolower($text)) ?: [];

        return array_values(array_filter($parts, static fn ($w) => strlen($w) > 2 && ! in_array($w, $stop, true)));
    }
}
