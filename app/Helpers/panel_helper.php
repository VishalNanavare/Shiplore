<?php

declare(strict_types=1);

/**
 * Panel helper — build a link/redirect that must cross from the panel currently
 * being served into a DIFFERENT panel's own route group.
 *
 * Every panel route group (admin, vendor, rider, manufacturer, monline) in
 * app/Config/Routes.php is restricted to registering only on its own subdomain via
 * the 'subdomain' route option — see PanelSubdomainIsolationTest for why. site_url()
 * and base_url() resolve against the CURRENT request's host, so a plain
 * site_url('vendor/dashboard') called while serving manufacturer.shiplore.in
 * produces a URL that will never match any registered route: same host, wrong
 * panel's path.
 *
 * This mirrors Admin\PortalController::portalUrl() exactly — the pattern the
 * codebase already established for exactly this problem (the admin "Go to Portal"
 * impersonation feature, built before this helper existed). Build $sub.<base-domain>
 * explicitly, validate it against Config\App::$allowedHostnames before trusting it
 * (defence in depth against a spoofed Host header), and fall back to a same-host
 * relative URL whenever the target can't be validated — e.g. local dev, where
 * shiplorelocal.in's subdomains are not listed in allowedHostnames, or a request
 * whose Host header isn't a normal dotted hostname at all.
 *
 * Usage in views:       panel_url('monline', 'monline/browse')
 * Usage in controllers: redirect()->to(panel_url('admin', 'admin/dashboard'))
 */
if (! function_exists('panel_url')) {
    function panel_url(string $sub, string $path): string
    {
        $host  = explode(':', (string) service('request')->getServer('HTTP_HOST'))[0];
        $parts = explode('.', $host);
        if (count($parts) > 2) {
            array_shift($parts); // drop the current subdomain label
        }
        $base   = implode('.', $parts);
        $target = $sub . '.' . $base;

        $allowed = config('App')->allowedHostnames ?? [];
        if (strpos($base, '.') === false || (! in_array($target, $allowed, true) && $target !== $host)) {
            return base_url($path);
        }

        $scheme = service('request')->isSecure() ? 'https' : 'http';

        return $scheme . '://' . $target . '/' . ltrim($path, '/');
    }
}
