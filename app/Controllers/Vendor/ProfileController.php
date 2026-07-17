<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use CodeIgniter\HTTP\RedirectResponse;
use Config\Database;

/**
 * Vendor\ProfileController — business profile / branding. The logo is stored
 * through MediaService (so it flows to S3/test_s3 like every other asset) and
 * referenced by vendors.logo_media_id. Only the owner may change it.
 */
final class ProfileController extends BaseVendorController
{
    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->isOwner()) {
            return redirect()->to('vendor/dashboard')->with('error', 'Only the owner can manage the business profile.');
        }
        $v        = $this->vendor();
        $logoUuid = null;
        if (! empty($v['logo_media_id'])) {
            $row = Database::connect()->table('media_assets')->select('uuid')
                ->where('id', (int) $v['logo_media_id'])->where('status', 'active')->where('deleted_at', null)
                ->get()->getRowArray();
            $logoUuid = $row['uuid'] ?? null;
        }

        return $this->render('vendor/profile/index', 'profile', 'Business Profile', ['logoUuid' => $logoUuid]);
    }

    public function uploadLogo(): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->isOwner()) {
            return redirect()->to('vendor/profile')->with('error', 'Only the owner can change the logo.');
        }
        $file = $this->request->getFile('logo');
        if ($file === null || ! $file->isValid()) {
            return redirect()->to('vendor/profile')->with('error', 'Please choose an image (JPG, PNG, WEBP or GIF).');
        }
        $res = service('mediaService')->store($file, 'vendor', (int) $this->vendorId(), (int) session()->get('user_id'), 'public', 'image');
        if (! ($res['ok'] ?? false)) {
            return redirect()->to('vendor/profile')->with('error', $res['reason'] ?? 'Upload failed.');
        }
        Database::connect()->table('vendors')->where('id', (int) $this->vendorId())
            ->update(['logo_media_id' => (int) $res['id']]);

        return redirect()->to('vendor/profile')->with('success', 'Logo updated.');
    }
}
