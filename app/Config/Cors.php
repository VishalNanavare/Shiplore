<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Cross-Origin Resource Sharing (CORS) Configuration
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
 */
class Cors extends BaseConfig
{
    /**
     * Browser clients (PWA / web app) call the JSON API cross-origin. Allowed
     * origins are opt-in via the `app.corsAllowedOrigins` env var (comma-separated)
     * so production stays locked down by default; native mobile apps don't use CORS.
     *
     * The literal token `panels` expands to this platform's own panel origins, built
     * from Config\App::$baseDomain — write it instead of listing all eight by hand:
     *
     *     app.corsAllowedOrigins = 'panels'
     *     app.corsAllowedOrigins = 'panels,https://partner.example.com'
     *
     * Spelling them out works too, but then the domain is repeated eight times and
     * changing app.baseDomain silently leaves eight stale origins behind — origins
     * that no longer match the site, so every cross-origin browser call fails with a
     * CORS error that names no cause.
     *
     * Expansion is deliberately opt-in rather than the default: auto-allowing all
     * panels whenever this is unset would quietly open CORS on every install, which is
     * the opposite of what the paragraph above promises.
     */
    public function __construct()
    {
        parent::__construct();

        $origins = (string) env('app.corsAllowedOrigins', '');
        if ($origins === '') {
            return;
        }

        $out = [];

        foreach (array_filter(array_map('trim', explode(',', $origins))) as $origin) {
            if ($origin === 'panels') {
                array_push($out, ...self::panelOrigins());

                continue;
            }
            $out[] = $origin;
        }

        $this->default['allowedOrigins'] = array_values(array_unique($out));
    }

    /**
     * One origin per allowed hostname, on the same scheme as the site's own base URL —
     * so a local http:// install expands to http:// origins rather than unreachable
     * https:// ones.
     *
     * @return list<string>
     */
    public static function panelOrigins(): array
    {
        $app    = config(App::class);
        $scheme = parse_url($app->baseURL, PHP_URL_SCHEME) ?: 'https';

        return array_map(
            static fn (string $host): string => $scheme . '://' . $host,
            $app->allowedHostnames,
        );
    }

    /**
     * The default CORS configuration.
     *
     * @var array{
     *      allowedOrigins: list<string>,
     *      allowedOriginsPatterns: list<string>,
     *      supportsCredentials: bool,
     *      allowedHeaders: list<string>,
     *      exposedHeaders: list<string>,
     *      allowedMethods: list<string>,
     *      maxAge: int,
     *  }
     */
    public array $default = [
        /**
         * Origins for the `Access-Control-Allow-Origin` header.
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Allow-Origin
         *
         * E.g.:
         *   - ['http://localhost:8080']
         *   - ['https://www.example.com']
         */
        'allowedOrigins' => [],

        /**
         * Origin regex patterns for the `Access-Control-Allow-Origin` header.
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Allow-Origin
         *
         * NOTE: A pattern specified here is part of a regular expression. It will
         *       be actually `#\A<pattern>\z#`.
         *
         * E.g.:
         *   - ['https://\w+\.example\.com']
         */
        'allowedOriginsPatterns' => [],

        /**
         * Weather to send the `Access-Control-Allow-Credentials` header.
         *
         * The Access-Control-Allow-Credentials response header tells browsers whether
         * the server allows cross-origin HTTP requests to include credentials.
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Allow-Credentials
         */
        'supportsCredentials' => false,

        /**
         * Set headers to allow.
         *
         * The Access-Control-Allow-Headers response header is used in response to
         * a preflight request which includes the Access-Control-Request-Headers to
         * indicate which HTTP headers can be used during the actual request.
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Allow-Headers
         */
        'allowedHeaders' => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With'],

        /**
         * Set headers to expose.
         *
         * The Access-Control-Expose-Headers response header allows a server to
         * indicate which response headers should be made available to scripts running
         * in the browser, in response to a cross-origin request.
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Expose-Headers
         */
        'exposedHeaders' => [],

        /**
         * Set methods to allow.
         *
         * The Access-Control-Allow-Methods response header specifies one or more
         * methods allowed when accessing a resource in response to a preflight
         * request.
         *
         * E.g.:
         *   - ['GET', 'POST', 'PUT', 'DELETE']
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Allow-Methods
         */
        'allowedMethods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

        /**
         * Set how many seconds the results of a preflight request can be cached.
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Access-Control-Max-Age
         */
        'maxAge' => 7200,
    ];
}
