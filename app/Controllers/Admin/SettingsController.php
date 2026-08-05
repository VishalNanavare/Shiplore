<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/** Admin\SettingsController — system settings, feature flags + GST config. settings.view. */
final class SettingsController extends BaseController
{
    /**
     * Server-detected mime => stored extension. A closed allow-list: anything not in it
     * is refused rather than given a fallback, so no client-supplied string can ever
     * reach the filename. Covers exactly the types the upload form's `accept` advertises.
     */
    private const LOGO_TYPES = [
        'image/png'     => 'png',
        'image/jpeg'    => 'jpg',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp',
        'image/svg+xml' => 'svg',
    ];

    public function index()
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $repo = service('settingsRepository');

        return view('admin/settings/index', [
            'title'     => 'System Settings · ' . $repo->brandName(),
            'pageTitle' => 'System Settings',
            'active'    => 'settings',
            'userName'  => session()->get('user_name') ?: 'User',
            'settings'  => $repo->settings(),
            'flags'     => $repo->featureFlags(),
            'gst'       => $repo->gstConfig(),
            'maxRadius' => $repo->deliveryMaxRadiusKm(),
            'brandName' => $repo->brandName(),
            'logoUrl'   => $repo->logoUrl(),
            'modules'   => array_map(fn ($k) => $repo->moduleEnabled($k), array_combine(
                array_keys(\App\Models\SettingsRepository::OPTIONAL_MODULES),
                array_keys(\App\Models\SettingsRepository::OPTIONAL_MODULES),
            )),
        ]);
    }

    /** Save the platform max delivery radius (km). */
    public function saveDelivery(): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $km = (float) $this->request->getPost('max_radius_km');
        if ($km <= 0) {
            return redirect()->to('admin/settings')->with('error', 'Max delivery radius must be greater than 0.');
        }
        service('settingsRepository')->setDeliveryMaxRadiusKm($km, (int) session()->get('user_id'));

        return redirect()->to('admin/settings')->with('success', 'Max delivery radius updated.');
    }

    /** Save system identity (brand name) + optional-module on/off toggles. */
    public function saveSystem(): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $repo  = service('settingsRepository');
        $uid   = (int) session()->get('user_id');
        $brand = trim((string) $this->request->getPost('brand_name'));
        $repo->set('system', 'brand_name', $brand !== '' ? mb_substr($brand, 0, 60) : 'Shiplore', 'string', $uid);

        // Logo: file upload takes priority; otherwise accept a pasted URL.
        $logo = $this->request->getFile('brand_logo');
        if ($logo !== null && $logo->isValid() && ! $logo->hasMoved()) {
            // The extension MUST come from the server-detected mime type. getClientExtension()
            // is pathinfo() over $_FILES['name'], so a file named "s.php" was written into
            // public/assets/images/ as logo_<time>.php — a directory the cPanel PHP handler
            // executes (see the AddHandler in .htaccess) — and could then be requested directly.
            $ext = self::LOGO_TYPES[strtolower(trim((string) $logo->getMimeType()))] ?? null;
            if ($ext === null) {
                return redirect()->to('admin/settings')
                    ->with('error', 'The logo must be a PNG, JPG, GIF, WEBP or SVG image.');
            }
            $name = 'logo_' . time() . '.' . $ext;
            $logo->move(FCPATH . 'assets/images/', $name, true);
            $repo->set('system', 'brand_logo_url', base_url('assets/images/' . $name), 'string', $uid);
        } elseif ($this->request->getPost('brand_logo_url') !== null) {
            $url = trim((string) $this->request->getPost('brand_logo_url'));
            if ($url !== '') {
                $repo->set('system', 'brand_logo_url', $url, 'string', $uid);
            }
        }

        foreach (array_keys(\App\Models\SettingsRepository::OPTIONAL_MODULES) as $module) {
            $repo->set('modules', $module . '_enabled', (bool) $this->request->getPost('module_' . $module), 'bool', $uid);
        }

        return redirect()->to('admin/settings')->with('success', 'System settings updated.');
    }

    private function guard(): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), 'settings.view')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
