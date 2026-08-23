<?php

namespace Config;

use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\Cors;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;

class Filters extends BaseFilters
{
    /**
     * Configures aliases for Filter classes to
     * make reading things nicer and simpler.
     *
     * @var array<string, class-string|list<class-string>>
     *
     * [filter_name => classname]
     * or [filter_name => [classname1, classname2, ...]]
     */
    public array $aliases = [
        // App\Filters\Csrf (extends the framework's) converts a failed AJAX POST's
        // thrown SecurityException into the same JSON envelope AjaxRedirectFilter
        // uses for a redirect, so ajax-forms.js can show it instead of silently
        // document.write()-ing a raw error page over the form.
        'csrf'          => \App\Filters\Csrf::class,
        'toolbar'       => DebugToolbar::class,
        'honeypot'      => Honeypot::class,
        'invalidchars'  => InvalidChars::class,
        // Audit L13: App\Filters\SecureHeaders (extends the framework's) adds the
        // clickjacking CSP + Permissions-Policy that used to exist only in the
        // project-root .htaccess, plus COOP/CORP which existed nowhere.
        'secureheaders' => \App\Filters\SecureHeaders::class,
        'cors'          => Cors::class,
        'forcehttps'    => ForceHTTPS::class,
        'pagecache'     => PageCache::class,
        'performance'   => PerformanceMetrics::class,
        // Auth/access spine (Phase 6) — apply per-route, e.g. ['filter' => ['jwtAuth', 'perm:order.view.own']]
        'jwtAuth'       => \App\Filters\JwtAuthFilter::class,
        'perm'          => \App\Filters\PermissionFilter::class,
        'tenantScope'   => \App\Filters\TenantScopeFilter::class,
        'webAuth'       => \App\Filters\WebAuthFilter::class,
        'riderAuth'     => \App\Filters\RiderAuthFilter::class,
        'throttle'      => \App\Filters\ThrottleFilter::class,
        // Converts redirect responses into JSON for AJAX form posts (web UX).
        'ajaxRedirect'  => \App\Filters\AjaxRedirectFilter::class,
    ];

    /**
     * List of special required filters.
     *
     * The filters listed here are special. They are applied before and after
     * other kinds of filters, and always applied even if a route does not exist.
     *
     * Filters set by default provide framework functionality. If removed,
     * those functions will no longer work.
     *
     * @see https://codeigniter.com/user_guide/incoming/filters.html#provided-filters
     *
     * @var array{before: list<string>, after: list<string>}
     */
    public array $required = [
        'before' => [
            'forcehttps', // Force Global Secure Requests
            'pagecache',  // Web Page Caching
        ],
        'after' => [
            'pagecache',   // Web Page Caching
            'performance', // Performance Metrics
            'toolbar',     // Debug Toolbar
        ],
    ];

    /**
     * List of filter aliases that are always
     * applied before and after every request.
     *
     * @var array{
     *     before: array<string, array{except: list<string>|string}>|list<string>,
     *     after: array<string, array{except: list<string>|string}>|list<string>
     * }
     */
    public array $globals = [
        'before' => [
            // 'honeypot',
            // 'csrf',
            // 'invalidchars',
        ],
        'after' => [
            // 'honeypot',
            // X-Frame-Options, X-Content-Type-Options, Referrer-Policy,
            // X-Download-Options, X-Permitted-Cross-Domain-Policies on every response.
            //
            // The project-root .htaccess already sets equivalents, but only while the
            // vhost keeps serving that file: it stops applying the moment the
            // DocumentRoot moves to public/ (the planned change) or the file is lost.
            // Setting them in the app too means the headers travel with the code.
            // Where both set the same header, Apache's `Header always set` wins, and
            // the two agree, so enabling this changes no effective behaviour today.
            'secureheaders',
            // Turn redirect responses into JSON for AJAX form posts (web only;
            // the JSON-native API under api/* keeps its own envelope).
            'ajaxRedirect' => ['except' => 'api/*'],
        ],
    ];

    /**
     * List of filter aliases that works on a
     * particular HTTP method (GET, POST, etc.).
     *
     * Example:
     * 'POST' => ['foo', 'bar']
     *
     * If you use this, you should disable auto-routing because auto-routing
     * permits any HTTP method to access a controller. Accessing the controller
     * with a method you don't expect could bypass the filter.
     *
     * @var array<string, list<string>>
     */
    public array $methods = [];

    /**
     * List of filter aliases that should run on any
     * before or after URI patterns.
     *
     * Example:
     * 'isLoggedIn' => ['before' => ['account/*', 'profiles/*']]
     *
     * @var array<string, array<string, list<string>>>
     */
    public array $filters = [
        // CORS for the JSON API (browser clients). Origins are opt-in via
        // app.corsAllowedOrigins; native apps are unaffected. Handles OPTIONS preflight.
        'cors' => ['before' => ['api/*'], 'after' => ['api/*']],
    ];
}
