<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class App extends BaseConfig
{
    /**
     * --------------------------------------------------------------------------
     * Base Site URL
     * --------------------------------------------------------------------------
     *
     * URL to your CodeIgniter root. Typically, this will be your base URL,
     * WITH a trailing slash:
     *
     * E.g., http://example.com/
     *
     * Recomputed from $baseDomain as `https://<domain>/` unless `app.baseURL` is set
     * explicitly, so moving domains is one .env line and this cannot drift out of step.
     * Set `app.baseURL` when the scheme is not https (local dev) or the app is not at
     * the domain root; that always wins, which is also how phpunit.dist.xml pins
     * http://example.com/ for the suite.
     *
     * The literal is a placeholder, NOT this platform's address — the real domain
     * belongs in .env and nowhere in tracked code. It stays a syntactically valid URL
     * rather than '' because Tests\Support\Libraries\ConfigReader reads this property
     * with the constructor deliberately bypassed and HealthTest validates that raw
     * value.
     */
    public string $baseURL = 'https://localhost/';

    /**
     * The root domain every panel hangs off, and the single value that moves the whole
     * platform: $baseURL above and $allowedHostnames below are both DERIVED from it.
     *
     * SET `app.baseDomain` IN .env — IT IS REQUIRED IN EVERY DEPLOYED ENVIRONMENT.
     * The `localhost` placeholder is not a working default and is not this platform's
     * address; tracked code deliberately names no real domain. Deploy without .env and
     * every panel hostname resolves to *.localhost, so CI4 rejects the real Host header
     * and site_url() emits links to localhost. That failure is loud and immediate by
     * design — the alternative, defaulting to a real domain, silently points a
     * misconfigured environment at production.
     *
     * Deliberately separate from $baseURL rather than parsed out of it: the suite pins
     * app.baseURL to http://example.com/ while driving requests at *.shiplore.test
     * hosts (phpunit.dist.xml sets both), so deriving the hostname list from $baseURL
     * would empty it under test and 65 test files would assert against unregistered
     * routes.
     */
    public string $baseDomain = 'localhost';

    /**
     * Every subdomain label the router pins a group to, WITHOUT the domain.
     *
     * Must stay in step with the 'subdomain' route options in Config\Routes — a label
     * missing here is not in $allowedHostnames, so SiteURIFactory refuses the host,
     * site_url() silently falls back to $baseURL's domain and every link on that panel
     * points at the wrong origin. AllowedHostnamesTest pins the two lists together.
     *
     * manufacturer./mshop. mirror vendor./shop. (owner login vs unit-staff login);
     * monline. is the B2B marketplace where vendors and shops buy from manufacturers.
     */
    public const PANEL_SUBDOMAINS = ['admin', 'vendor', 'shop', 'rider', 'manufacturer', 'mshop', 'monline'];

    /**
     * Allowed Hostnames in the Site URL other than the hostname in the baseURL.
     *
     * Derived from $baseDomain in the constructor — do not hand-edit. Left empty here
     * rather than populated so an explicit value (from .env, or a test) is
     * distinguishable from "not set yet" and wins.
     *
     * This list is load-bearing beyond CI4's host validation: SiteURIFactory
     * substitutes the REQUEST's host into site_url() only when that host appears here,
     * which is what keeps every panel's links on its own origin.
     *
     * @var list<string>
     */
    public array $allowedHostnames = [];

    public function __construct()
    {
        parent::__construct(); // binds app.baseURL / app.baseDomain from .env first

        // $baseDomain is the single source of truth; both values below follow it unless
        // something explicit was supplied. env() reads $_ENV/$_SERVER/getenv, so this
        // sees .env AND phpunit.dist.xml's <server name="app.baseURL"> — which is why
        // the suite keeps its http://example.com/ while production derives from the
        // domain.
        if (env('app.baseURL') === null) {
            $this->baseURL = 'https://' . trim($this->baseDomain, " \t.") . '/';
        }

        if ($this->allowedHostnames === []) {
            $this->allowedHostnames = self::hostnamesFor($this->baseDomain);
        }
    }

    /**
     * The root domain plus one host per panel subdomain.
     *
     * @return list<string>
     */
    public static function hostnamesFor(string $baseDomain): array
    {
        $root  = trim($baseDomain, " \t.");
        $hosts = [$root];

        foreach (self::PANEL_SUBDOMAINS as $label) {
            $hosts[] = $label . '.' . $root;
        }

        return $hosts;
    }

    /**
     * --------------------------------------------------------------------------
     * Index File
     * --------------------------------------------------------------------------
     *
     * Typically, this will be your `index.php` file, unless you've renamed it to
     * something else. If you have configured your web server to remove this file
     * from your site URIs, set this variable to an empty string.
     */
    public string $indexPage = '';

    /**
     * --------------------------------------------------------------------------
     * URI PROTOCOL
     * --------------------------------------------------------------------------
     *
     * This item determines which server global should be used to retrieve the
     * URI string. The default setting of 'REQUEST_URI' works for most servers.
     * If your links do not seem to work, try one of the other delicious flavors:
     *
     *  'REQUEST_URI': Uses $_SERVER['REQUEST_URI']
     * 'QUERY_STRING': Uses $_SERVER['QUERY_STRING']
     *    'PATH_INFO': Uses $_SERVER['PATH_INFO']
     *
     * WARNING: If you set this to 'PATH_INFO', URIs will always be URL-decoded!
     */
    public string $uriProtocol = 'REQUEST_URI';

    /*
    |--------------------------------------------------------------------------
    | Allowed URL Characters
    |--------------------------------------------------------------------------
    |
    | This lets you specify which characters are permitted within your URLs.
    | When someone tries to submit a URL with disallowed characters they will
    | get a warning message.
    |
    | As a security measure you are STRONGLY encouraged to restrict URLs to
    | as few characters as possible.
    |
    | By default, only these are allowed: `a-z 0-9~%.:_-`
    |
    | Set an empty string to allow all characters -- but only if you are insane.
    |
    | The configured value is actually a regular expression character group
    | and it will be used as: '/\A[<permittedURIChars>]+\z/iu'
    |
    | DO NOT CHANGE THIS UNLESS YOU FULLY UNDERSTAND THE REPERCUSSIONS!!
    |
    */
    public string $permittedURIChars = 'a-z 0-9~%.:_\-';

    /**
     * --------------------------------------------------------------------------
     * Default Locale
     * --------------------------------------------------------------------------
     *
     * The Locale roughly represents the language and location that your visitor
     * is viewing the site from. It affects the language strings and other
     * strings (like currency markers, numbers, etc), that your program
     * should run under for this request.
     */
    public string $defaultLocale = 'en';

    /**
     * --------------------------------------------------------------------------
     * Negotiate Locale
     * --------------------------------------------------------------------------
     *
     * If true, the current Request object will automatically determine the
     * language to use based on the value of the Accept-Language header.
     *
     * If false, no automatic detection will be performed.
     */
    public bool $negotiateLocale = false;

    /**
     * --------------------------------------------------------------------------
     * Supported Locales
     * --------------------------------------------------------------------------
     *
     * If $negotiateLocale is true, this array lists the locales supported
     * by the application in descending order of priority. If no match is
     * found, the first locale will be used.
     *
     * IncomingRequest::setLocale() also uses this list.
     *
     * @var list<string>
     */
    public array $supportedLocales = ['en'];

    /**
     * --------------------------------------------------------------------------
     * Application Timezone
     * --------------------------------------------------------------------------
     *
     * The default timezone that will be used in your application to display
     * dates with the date helper, and can be retrieved through app_timezone()
     *
     * @see https://www.php.net/manual/en/timezones.php for list of timezones
     *      supported by PHP.
     */
    public string $appTimezone = 'UTC';

    /**
     * --------------------------------------------------------------------------
     * Default Character Set
     * --------------------------------------------------------------------------
     *
     * This determines which character set is used by default in various methods
     * that require a character set to be provided.
     *
     * @see http://php.net/htmlspecialchars for a list of supported charsets.
     */
    public string $charset = 'UTF-8';

    /**
     * --------------------------------------------------------------------------
     * Force Global Secure Requests
     * --------------------------------------------------------------------------
     *
     * If true, this will force every request made to this application to be
     * made via a secure connection (HTTPS). If the incoming request is not
     * secure, the user will be redirected to a secure version of the page
     * and the HTTP Strict Transport Security (HSTS) header will be set.
     */
    public bool $forceGlobalSecureRequests = false;

    /**
     * --------------------------------------------------------------------------
     * Reverse Proxy IPs
     * --------------------------------------------------------------------------
     *
     * If your server is behind a reverse proxy, you must whitelist the proxy
     * IP addresses from which CodeIgniter should trust headers such as
     * X-Forwarded-For or Client-IP in order to properly identify
     * the visitor's IP address.
     *
     * You need to set a proxy IP address or IP address with subnets and
     * the HTTP header for the client IP address.
     *
     * Here are some examples:
     *     [
     *         '10.0.1.200'     => 'X-Forwarded-For',
     *         '192.168.5.0/24' => 'X-Real-IP',
     *     ]
     *
     * @var array<string, string>
     */
    public array $proxyIPs = [];

    /**
     * --------------------------------------------------------------------------
     * Content Security Policy
     * --------------------------------------------------------------------------
     *
     * Enables the Response's Content Secure Policy to restrict the sources that
     * can be used for images, scripts, CSS files, audio, video, etc. If enabled,
     * the Response object will populate default values for the policy from the
     * `ContentSecurityPolicy.php` file. Controllers can always add to those
     * restrictions at run time.
     *
     * For a better understanding of CSP, see these documents:
     *
     * @see http://www.html5rocks.com/en/tutorials/security/content-security-policy/
     * @see http://www.w3.org/TR/CSP/
     */
    /**
     * Enabled in REPORT-ONLY mode — see app/Config/ContentSecurityPolicy.php
     * ($reportOnly = true). Browsers will report violations but never block, so this
     * cannot break a page. Its job right now is to produce the inventory of inline
     * scripts and third-party hosts needed before an enforcing policy is safe.
     */
    public bool $CSPEnabled = true;
}
