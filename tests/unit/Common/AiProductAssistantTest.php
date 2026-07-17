<?php

declare(strict_types=1);

use App\Libraries\Ai\AiProductAssistant;
use CodeIgniter\Test\CIUnitTestCase as TestCase;

/** P8 — deterministic AI dev-stub for SEO/tags/description. */
final class AiProductAssistantTest extends TestCase
{
    public function testStubSeoComposesFromFields(): void
    {
        $seo = AiProductAssistant::stubSeo('Running Sneakers', 'Footwear', 'Sole Mate');
        $this->assertSame('Running Sneakers by Sole Mate | Buy Online', $seo['meta_title']);
        $this->assertStringContainsString('Running Sneakers', $seo['meta_description']);
        $this->assertStringContainsString('Footwear', $seo['meta_description']);
        $this->assertStringContainsString('running', $seo['meta_keywords']);
        $this->assertStringContainsString('footwear', $seo['meta_keywords']);
    }

    public function testStubSeoIsDeterministic(): void
    {
        $a = AiProductAssistant::stubSeo('Widget', 'Tools', '');
        $b = AiProductAssistant::stubSeo('Widget', 'Tools', '');
        $this->assertSame($a, $b);
    }

    public function testStubTagsDropShortAndStopWords(): void
    {
        $tags = AiProductAssistant::stubTags('The New Pro Camera', 'Electronics');
        $this->assertContains('camera', $tags);
        $this->assertContains('electronics', $tags);
        $this->assertNotContains('the', $tags);   // stop word
        $this->assertNotContains('new', $tags);   // stop word
    }

    public function testStubDescriptionMentionsTitleAndBrand(): void
    {
        $d = AiProductAssistant::stubDescription('Yoga Mat', 'Fitness', 'FlexCo');
        $this->assertStringContainsString('Yoga Mat', $d);
        $this->assertStringContainsString('FlexCo', $d);
        $this->assertStringContainsString('Fitness', $d);
    }

    public function testSuggestDispatchesByKind(): void
    {
        $a = new AiProductAssistant();
        $this->assertArrayHasKey('meta_title', $a->suggest('seo', ['title' => 'Lamp', 'category' => 'Home', 'brand' => '']));
        $this->assertArrayHasKey('tags', $a->suggest('tags', ['title' => 'Lamp']));
        $this->assertArrayHasKey('description', $a->suggest('description', ['title' => 'Lamp']));
        $this->assertSame([], $a->suggest('unknown', ['title' => 'Lamp']));
    }
}
