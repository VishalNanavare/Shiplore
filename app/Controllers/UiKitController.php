<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * UiKitController — serves the standalone UI Kit: a self-contained design
 * reference theme (own shell + menu + demo pages) that is intentionally NOT
 * wired to the application. It is a living style guide for the team.
 *
 * The MENU constant is the single source of truth — the sidebar renders from
 * it and routing validates slugs against it.
 */
final class UiKitController extends BaseController
{
    /** @var array<int,array{header:string,items:array<int,array{slug:string,title:string,icon:string}>}> */
    private const MENU = [
        ['header' => 'Dashboards', 'items' => [
            ['slug' => 'dashboard-analytics', 'title' => 'Analytics',  'icon' => 'bi-graph-up-arrow'],
            ['slug' => 'dashboard-ecommerce', 'title' => 'eCommerce',  'icon' => 'bi-cart-check'],
            ['slug' => 'dashboard-crm',       'title' => 'CRM',        'icon' => 'bi-people'],
            ['slug' => 'dashboard-project',   'title' => 'Project',    'icon' => 'bi-kanban'],
        ]],
        ['header' => 'Apps', 'items' => [
            ['slug' => 'chat',             'title' => 'Chat',            'icon' => 'bi-chat-dots'],
            ['slug' => 'app-email',        'title' => 'Email',           'icon' => 'bi-envelope'],
            ['slug' => 'app-calendar',     'title' => 'Calendar',        'icon' => 'bi-calendar3'],
            ['slug' => 'app-kanban',       'title' => 'Kanban',          'icon' => 'bi-kanban'],
            ['slug' => 'app-todo',         'title' => 'Todo',            'icon' => 'bi-check2-square'],
            ['slug' => 'app-users',        'title' => 'User List',       'icon' => 'bi-people'],
            ['slug' => 'app-user-view',    'title' => 'User View',       'icon' => 'bi-person-lines-fill'],
            ['slug' => 'app-user-edit',    'title' => 'User Edit',       'icon' => 'bi-person-gear'],
            ['slug' => 'profile',          'title' => 'Profile',         'icon' => 'bi-person-badge'],
            ['slug' => 'account-settings', 'title' => 'Account Settings','icon' => 'bi-sliders'],
            ['slug' => 'file-manager',     'title' => 'File Manager',    'icon' => 'bi-folder2-open'],
            ['slug' => 'invoice',          'title' => 'Invoice Preview', 'icon' => 'bi-receipt'],
            ['slug' => 'invoice-list',     'title' => 'Invoice List',    'icon' => 'bi-card-list'],
            ['slug' => 'invoice-edit',     'title' => 'Invoice Edit',    'icon' => 'bi-pencil-square'],
        ]],
        ['header' => 'eCommerce', 'items' => [
            ['slug' => 'ecommerce-products',         'title' => 'Product List',    'icon' => 'bi-grid'],
            ['slug' => 'ecommerce-product-view',     'title' => 'Product Detail',  'icon' => 'bi-box-seam'],
            ['slug' => 'ecommerce-product-add',      'title' => 'Add Product',     'icon' => 'bi-plus-square'],
            ['slug' => 'ecommerce-product-variants', 'title' => 'Product Variants','icon' => 'bi-diagram-3'],
            ['slug' => 'ecommerce-wishlist',         'title' => 'Wishlist',        'icon' => 'bi-heart'],
            ['slug' => 'ecommerce-checkout',         'title' => 'Checkout',        'icon' => 'bi-credit-card-2-back'],
            ['slug' => 'pricing',                    'title' => 'Pricing',         'icon' => 'bi-tags'],
        ]],
        ['header' => 'Components', 'items' => [
            ['slug' => 'alerts',      'title' => 'Alerts',           'icon' => 'bi-exclamation-triangle'],
            ['slug' => 'avatars',     'title' => 'Avatars',          'icon' => 'bi-person-circle'],
            ['slug' => 'badges',      'title' => 'Badges',           'icon' => 'bi-patch-check'],
            ['slug' => 'breadcrumbs', 'title' => 'Breadcrumbs',      'icon' => 'bi-signpost-split'],
            ['slug' => 'buttons',     'title' => 'Buttons',          'icon' => 'bi-hand-index-thumb'],
            ['slug' => 'cards',       'title' => 'Cards',            'icon' => 'bi-credit-card-2-front'],
            ['slug' => 'carousel',    'title' => 'Carousel',         'icon' => 'bi-images'],
            ['slug' => 'accordion',   'title' => 'Collapse',         'icon' => 'bi-chevron-bar-contract'],
            ['slug' => 'dropdowns',   'title' => 'Dropdowns',        'icon' => 'bi-menu-button-wide'],
            ['slug' => 'list-group',  'title' => 'List Group',       'icon' => 'bi-list-ul'],
            ['slug' => 'modals',      'title' => 'Modals',           'icon' => 'bi-window-stack'],
            ['slug' => 'pagination',  'title' => 'Pagination',       'icon' => 'bi-three-dots'],
            ['slug' => 'progress',    'title' => 'Progress',         'icon' => 'bi-bar-chart-steps'],
            ['slug' => 'spinners',    'title' => 'Spinners',         'icon' => 'bi-arrow-repeat'],
            ['slug' => 'tabs',        'title' => 'Tabs & Pills',     'icon' => 'bi-segmented-nav'],
            ['slug' => 'navs',        'title' => 'Navs',             'icon' => 'bi-list-nested'],
            ['slug' => 'media-objects','title' => 'Media Objects',   'icon' => 'bi-card-image'],
            ['slug' => 'dividers',    'title' => 'Dividers',         'icon' => 'bi-dash-lg'],
            ['slug' => 'timeline',    'title' => 'Timeline',         'icon' => 'bi-clock-history'],
            ['slug' => 'toasts',      'title' => 'Toasts',           'icon' => 'bi-bell'],
            ['slug' => 'tooltips',    'title' => 'Tooltips & Popovers', 'icon' => 'bi-chat-square-text'],
        ]],
        ['header' => 'Forms & Tables', 'items' => [
            ['slug' => 'form-elements',     'title' => 'Form Elements',    'icon' => 'bi-input-cursor-text'],
            ['slug' => 'form-layouts',      'title' => 'Form Layouts',     'icon' => 'bi-layout-text-window'],
            ['slug' => 'form-validation',   'title' => 'Form Validation',  'icon' => 'bi-check2-circle'],
            ['slug' => 'form-wizard',       'title' => 'Form Wizard',      'icon' => 'bi-signpost-2'],
            ['slug' => 'form-datepicker',   'title' => 'Date & Time',      'icon' => 'bi-calendar-date'],
            ['slug' => 'form-editor',       'title' => 'Rich Editor',      'icon' => 'bi-pencil-square'],
            ['slug' => 'form-file-upload',  'title' => 'File Upload',      'icon' => 'bi-cloud-arrow-up'],
            ['slug' => 'form-input-mask',   'title' => 'Input Mask',       'icon' => 'bi-credit-card'],
            ['slug' => 'form-number',       'title' => 'Number Input',     'icon' => 'bi-123'],
            ['slug' => 'form-repeater',     'title' => 'Form Repeater',    'icon' => 'bi-plus-circle-dotted'],
            ['slug' => 'select2',           'title' => 'Select2',          'icon' => 'bi-menu-button-wide'],
            ['slug' => 'autocomplete',      'title' => 'Autocomplete',     'icon' => 'bi-search'],
            ['slug' => 'table-basic',       'title' => 'Tables',           'icon' => 'bi-table'],
            ['slug' => 'table-datatable',   'title' => 'DataTables',       'icon' => 'bi-grid-3x3-gap'],
            ['slug' => 'datatable-advanced','title' => 'DataTables Adv.',  'icon' => 'bi-ui-checks'],
        ]],
        ['header' => 'Extensions', 'items' => [
            ['slug' => 'sweetalert', 'title' => 'SweetAlert2', 'icon' => 'bi-emoji-smile'],
            ['slug' => 'toastr',     'title' => 'Toastr',      'icon' => 'bi-bell-fill'],
            ['slug' => 'ratings',    'title' => 'Ratings',     'icon' => 'bi-star-half'],
            ['slug' => 'sliders',    'title' => 'Sliders',     'icon' => 'bi-sliders2'],
            ['slug' => 'swiper',     'title' => 'Swiper',      'icon' => 'bi-collection-play'],
            ['slug' => 'clipboard',  'title' => 'Clipboard',   'icon' => 'bi-clipboard-check'],
            ['slug' => 'drag-drop',  'title' => 'Drag & Drop', 'icon' => 'bi-arrows-move'],
            ['slug' => 'blockui',    'title' => 'Block UI',    'icon' => 'bi-hourglass-split'],
        ]],
        ['header' => 'Charts & Maps', 'items' => [
            ['slug' => 'charts-chartjs', 'title' => 'Chart.js',     'icon' => 'bi-pie-chart'],
            ['slug' => 'charts-apex',    'title' => 'ApexCharts',   'icon' => 'bi-bar-chart'],
            ['slug' => 'maps-leaflet',   'title' => 'Leaflet Maps', 'icon' => 'bi-geo-alt'],
        ]],
        ['header' => 'Pages', 'items' => [
            ['slug' => 'auth-login',        'title' => 'Login',          'icon' => 'bi-box-arrow-in-right'],
            ['slug' => 'auth-register',     'title' => 'Register',       'icon' => 'bi-person-plus'],
            ['slug' => 'auth-forgot',       'title' => 'Forgot Password','icon' => 'bi-question-circle'],
            ['slug' => 'auth-reset',        'title' => 'Reset Password', 'icon' => 'bi-shield-lock'],
            ['slug' => 'error-404',         'title' => 'Error 404',      'icon' => 'bi-emoji-frown'],
            ['slug' => 'error-500',         'title' => 'Error 500',      'icon' => 'bi-bug'],
            ['slug' => 'error-maintenance', 'title' => 'Maintenance',    'icon' => 'bi-cone-striped'],
            ['slug' => 'coming-soon',       'title' => 'Coming Soon',    'icon' => 'bi-rocket-takeoff'],
            ['slug' => 'not-authorized',    'title' => 'Not Authorized', 'icon' => 'bi-shield-x'],
            ['slug' => 'knowledge-base',    'title' => 'Knowledge Base', 'icon' => 'bi-journal-bookmark'],
            ['slug' => 'blog-list',         'title' => 'Blog List',      'icon' => 'bi-newspaper'],
            ['slug' => 'blog-detail',       'title' => 'Blog Detail',    'icon' => 'bi-file-post'],
            ['slug' => 'faq',               'title' => 'FAQ',            'icon' => 'bi-patch-question'],
        ]],
        ['header' => 'User Interface', 'items' => [
            ['slug' => 'typography', 'title' => 'Typography', 'icon' => 'bi-fonts'],
            ['slug' => 'colors',     'title' => 'Colors',     'icon' => 'bi-palette'],
            ['slug' => 'icons',      'title' => 'Icons',      'icon' => 'bi-stars'],
        ]],
    ];

    /**
     * Public read-only accessor for the menu — the single source of truth shared
     * by the sidebar, slug routing, and the `uikit:export` standalone builder.
     *
     * @return array<int,array{header:string,items:array<int,array{slug:string,title:string,icon:string}>}>
     */
    public static function menu(): array
    {
        return self::MENU;
    }

    public function index(): string
    {
        return view('uikit/pages/home', [
            'menu'   => self::MENU,
            'active' => 'home',
            'title'  => 'Overview',
        ]);
    }

    public function show(string $slug): string
    {
        $title = $this->titleFor($slug);
        if ($title === null) {
            throw PageNotFoundException::forPageNotFound('Unknown UI Kit page: ' . $slug);
        }

        return view('uikit/pages/' . $slug, [
            'menu'   => self::MENU,
            'active' => $slug,
            'title'  => $title,
        ]);
    }

    private function titleFor(string $slug): ?string
    {
        foreach (self::MENU as $group) {
            foreach ($group['items'] as $item) {
                if ($item['slug'] === $slug) {
                    return $item['title'];
                }
            }
        }

        return null;
    }
}
