<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Phase 1–2 UI — verifies the themed pages render through the centralized
 * layouts and reference the local Bootstrap 5 / jQuery / app assets.
 */
final class UiRenderTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testLoginPageRendersWithLocalAssets(): void
    {
        $result = $this->get('login'); // staff login moved off the root (root = storefront)
        $result->assertStatus(200);

        $body = $result->getBody();
        $this->assertStringContainsString('assets/vendor/bootstrap/css/bootstrap.min.css', $body);
        $this->assertStringContainsString('assets/vendor/jquery/jquery.min.js', $body);
        $this->assertStringContainsString('assets/css/app.css', $body);
        $this->assertStringContainsString('name="csrf-hash"', $body);
        $this->assertStringContainsString('name="login"', $body);   // login form field
        $this->assertStringContainsString('Sign In', $body);
    }

    public function testUiKitHomeRendersStandaloneShell(): void
    {
        $result = $this->get('ui-kit');
        $result->assertStatus(200);

        $body = $result->getBody();
        $this->assertStringContainsString('uk-sidebar', $body);                 // standalone kit shell
        $this->assertStringContainsString('assets/css/uikit.css', $body);
        $this->assertStringContainsString('Dashboards', $body);                 // menu group rendered from MENU
        $this->assertStringContainsString('ui-kit/dashboard-analytics', $body); // menu link
        $this->assertStringContainsString('assets/plugins/chartjs/chart.umd.min.js', $body);
        $this->assertStringContainsString('assets/vendor/bootstrap/js/bootstrap.bundle.min.js', $body);
    }

    public function testUiKitDashboardPageRenders(): void
    {
        $result = $this->get('ui-kit/dashboard-analytics');
        $result->assertStatus(200);
        $this->assertStringContainsString('chartTrend', $result->getBody()); // chart canvas
    }

    public function testUiKitDataTablePageLoadsPlugin(): void
    {
        $result = $this->get('ui-kit/table-datatable');
        $result->assertStatus(200);
        $this->assertStringContainsString('plugins/datatables/dataTables.bootstrap5', $result->getBody());
    }

    public function testUiKitUnknownPageIs404(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->get('ui-kit/does-not-exist');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('uiKitSlugs')]
    public function testEveryUiKitPageRenders(string $slug): void
    {
        $this->get('ui-kit/' . $slug)->assertStatus(200);
    }

    public static function uiKitSlugs(): array
    {
        $slugs = [
            'dashboard-analytics', 'dashboard-ecommerce', 'dashboard-crm', 'dashboard-project',
            'chat', 'app-email', 'app-calendar', 'app-kanban', 'app-todo', 'app-users', 'app-user-view',
            'app-user-edit', 'profile', 'account-settings', 'file-manager', 'invoice', 'invoice-list', 'invoice-edit',
            'ecommerce-products', 'ecommerce-product-view', 'ecommerce-product-add', 'ecommerce-product-variants',
            'ecommerce-wishlist', 'ecommerce-checkout', 'pricing',
            'alerts', 'avatars', 'badges', 'breadcrumbs', 'buttons', 'cards', 'carousel', 'accordion',
            'dropdowns', 'list-group', 'modals', 'pagination', 'progress', 'spinners', 'tabs', 'navs',
            'media-objects', 'dividers', 'timeline', 'toasts', 'tooltips',
            'form-elements', 'form-layouts', 'form-validation', 'form-wizard', 'form-datepicker', 'form-editor',
            'form-file-upload', 'form-input-mask', 'form-number', 'form-repeater', 'select2', 'autocomplete',
            'table-basic', 'table-datatable', 'datatable-advanced',
            'sweetalert', 'toastr', 'ratings', 'sliders', 'swiper', 'clipboard', 'drag-drop', 'blockui',
            'charts-chartjs', 'charts-apex', 'maps-leaflet',
            'auth-login', 'auth-register', 'auth-forgot', 'auth-reset', 'error-404', 'error-500', 'error-maintenance',
            'coming-soon', 'not-authorized', 'knowledge-base', 'blog-list', 'blog-detail', 'faq',
            'typography', 'colors', 'icons',
        ];

        return array_map(static fn ($s) => [$s], $slugs);
    }
}
