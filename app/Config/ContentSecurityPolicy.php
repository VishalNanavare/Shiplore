<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Stores the default settings for the ContentSecurityPolicy, if you
 * choose to use it. The values here will be read in and set as defaults
 * for the site. If needed, they can be overridden on a page-by-page basis.
 *
 * Suggested reference for explanations:
 *
 * @see https://www.html5rocks.com/en/tutorials/security/content-security-policy/
 */
class ContentSecurityPolicy extends BaseConfig
{
    // -------------------------------------------------------------------------
    // Broadbrush CSP management
    // -------------------------------------------------------------------------

    /**
     * Default CSP report context
     *
     * REPORT-ONLY, deliberately. This emits `Content-Security-Policy-Report-Only`,
     * which browsers never enforce — it only reports what WOULD have been blocked.
     * That matters twice over here:
     *
     *  1. The app relies heavily on inline <script> blocks and inline event handlers
     *     (onsubmit/onclick in the admin portal views). An enforcing CSP without
     *     nonces would break those pages instantly.
     *  2. It is a different header from the `Content-Security-Policy: frame-ancestors
     *     'self'` that the project-root .htaccess sets with `Header always set`, so
     *     the two do not fight. An enforcing policy here would simply be replaced by
     *     Apache's and silently do nothing.
     *
     * Collect the violations from a real traffic day, use them to build the script
     * allow-list (autoNonce below is already on, so {csp-script-nonce} placeholders
     * can be added to the inline blocks), then flip this to false to enforce.
     */
    public bool $reportOnly = true;

    /**
     * Specifies a URL where a browser will send reports
     * when a content security policy is violated.
     *
     * Without this, reportOnly above has nowhere to send what it collects — zero
     * protection AND zero telemetry, and the rollout plan documented on
     * $reportOnly (collect a traffic day, build the allow-list, then enforce)
     * could never start. See App\Controllers\CspReportController.
     */
    public ?string $reportURI = '/csp-report';

    /**
     * Specifies a reporting endpoint to which violation reports ought to be sent.
     */
    public ?string $reportTo = null;

    /**
     * Instructs user agents to rewrite URL schemes, changing
     * HTTP to HTTPS. This directive is for websites with
     * large numbers of old URLs that need to be rewritten.
     */
    public bool $upgradeInsecureRequests = false;

    // -------------------------------------------------------------------------
    // CSP DIRECTIVES SETTINGS
    // NOTE: once you set a policy to 'none', it cannot be further restricted
    // -------------------------------------------------------------------------

    /**
     * Will default to `'self'` if not overridden
     *
     * @var list<string>|string|null
     */
    public $defaultSrc;

    /**
     * Lists allowed scripts' URLs.
     *
     * @var list<string>|string
     */
    /**
     * Third-party script hosts actually referenced by app/Views:
     *   www.gstatic.com     — Firebase JS SDK (phone auth on all four sign-in pages)
     *   www.google.com      — reCAPTCHA, required by Firebase phone auth
     *   maps.googleapis.com — address picker on checkout / store location
     *
     * Inline <script> blocks and inline handlers are deliberately NOT allowed here.
     * While reportOnly is true they cost nothing and each violation report is the
     * inventory needed to add {csp-script-nonce} placeholders before enforcing.
     */
    public $scriptSrc = ['self', 'https://www.gstatic.com', 'https://www.google.com', 'https://maps.googleapis.com'];

    /**
     * Specifies valid sources for JavaScript <script> elements.
     *
     * @var list<string>|string
     */
    public array|string $scriptSrcElem = 'self';

    /**
     * Specifies valid sources for JavaScript inline event
     * handlers and JavaScript URLs.
     *
     * @var list<string>|string
     */
    public array|string $scriptSrcAttr = 'self';

    /**
     * Lists allowed stylesheets' URLs.
     *
     * @var list<string>|string
     */
    public $styleSrc = 'self';

    /**
     * Specifies valid sources for stylesheets <link> elements.
     *
     * @var list<string>|string
     */
    public array|string $styleSrcElem = 'self';

    /**
     * Specifies valid sources for stylesheets inline
     * style attributes and `<style>` elements.
     *
     * @var list<string>|string
     */
    public array|string $styleSrcAttr = 'self';

    /**
     * Defines the origins from which images can be loaded.
     *
     * @var list<string>|string
     */
    public $imageSrc = 'self';

    /**
     * Restricts the URLs that can appear in a page's `<base>` element.
     *
     * Will default to self if not overridden
     *
     * @var list<string>|string|null
     */
    public $baseURI;

    /**
     * Lists the URLs for workers and embedded frame contents
     *
     * @var list<string>|string
     */
    public $childSrc = 'self';

    /**
     * Limits the origins that you can connect to (via XHR,
     * WebSockets, and EventSource).
     *
     * @var list<string>|string
     */
    /** Firebase auth and the Maps/Places APIs are called via XHR from the browser. */
    public $connectSrc = ['self', 'https://identitytoolkit.googleapis.com', 'https://securetoken.googleapis.com', 'https://maps.googleapis.com'];

    /**
     * Specifies the origins that can serve web fonts.
     *
     * @var list<string>|string
     */
    public $fontSrc;

    /**
     * Lists valid endpoints for submission from `<form>` tags.
     *
     * @var list<string>|string
     */
    public $formAction = 'self';

    /**
     * Specifies the sources that can embed the current page.
     * This directive applies to `<frame>`, `<iframe>`, `<embed>`,
     * and `<applet>` tags. This directive can't be used in
     * `<meta>` tags and applies only to non-HTML resources.
     *
     * @var list<string>|string|null
     */
    public $frameAncestors;

    /**
     * The frame-src directive restricts the URLs which may
     * be loaded into nested browsing contexts.
     *
     * @var list<string>|string|null
     */
    public $frameSrc;

    /**
     * Restricts the origins allowed to deliver video and audio.
     *
     * @var list<string>|string|null
     */
    public $mediaSrc;

    /**
     * Allows control over Flash and other plugins.
     *
     * @var list<string>|string
     */
    public $objectSrc = 'self';

    /**
     * @var list<string>|string|null
     */
    public $manifestSrc;

    /**
     * @var list<string>|string
     */
    public array|string $workerSrc = [];

    /**
     * Limits the kinds of plugins a page may invoke.
     *
     * @var list<string>|string|null
     */
    public $pluginTypes;

    /**
     * List of actions allowed.
     *
     * @var list<string>|string|null
     */
    public $sandbox;

    /**
     * Nonce placeholder for style tags.
     */
    public string $styleNonceTag = '{csp-style-nonce}';

    /**
     * Nonce placeholder for script tags.
     */
    public string $scriptNonceTag = '{csp-script-nonce}';

    /**
     * Replace nonce tag automatically?
     */
    public bool $autoNonce = true;
}
