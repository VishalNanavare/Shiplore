<?php

declare(strict_types=1);

namespace App\Controllers\Manufacturer;

use CodeIgniter\HTTP\RedirectResponse;
use Config\Database;

/**
 * Manufacturer\ProfileController — business profile and branding for the
 * manufacturer itself (as opposed to MeController, which is the acting person).
 *
 * Owner-only, mirroring Vendor\ProfileController: this is the tenant's legal and
 * public identity, and unit staff have no business rewriting it. The ownership test
 * comes before the permission test on purpose — a unit manager can legitimately hold
 * mfg.profile.view for the read screen without being allowed to change branding.
 *
 * A manufacturer IS a `vendors` row (party_type='manufacturer'), so the logo lives in
 * exactly the same place a vendor's does: stored through MediaService and referenced
 * by vendors.logo_media_id. No parallel column, no parallel table.
 *
 * @see \App\Controllers\Vendor\ProfileController — the vendor counterpart
 */
final class ProfileController extends BaseManufacturerController
{
    public function index()
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->requireOwner('manufacturer/dashboard', 'Only the owner can manage the business profile.')) {
            return $denied;
        }

        return $this->render('manufacturer/profile/index', 'profile', 'Business Profile', [
            'logoUuid' => $this->logoUuid(),
        ]);
    }

    public function uploadLogo(): RedirectResponse
    {
        if ($denied = $this->requireManufacturer()) {
            return $denied;
        }
        if ($denied = $this->requireOwner('manufacturer/profile', 'Only the owner can change the logo.')) {
            return $denied;
        }

        $file = $this->request->getFile('logo');
        if ($file === null || ! $file->isValid()) {
            return redirect()->to('manufacturer/profile')->with('error', 'Please choose an image (JPG, PNG, WEBP or GIF).');
        }

        // 'vendor' owner_type, not 'manufacturer': media_assets keys owners by the
        // vendors row this manufacturer already is, and MediaService validates the
        // type against that table. A new owner_type would orphan the asset.
        $res = service('mediaService')->store(
            $file,
            'vendor',
            (int) $this->manufacturerId(),
            (int) session()->get('user_id'),
            'public',
            'image',
        );
        if (! ($res['ok'] ?? false)) {
            return redirect()->to('manufacturer/profile')->with('error', $res['reason'] ?? 'Upload failed.');
        }

        Database::connect()->table('vendors')
            ->where('id', (int) $this->manufacturerId())
            ->update(['logo_media_id' => (int) $res['id']]);

        return redirect()->to('manufacturer/profile')->with('success', 'Logo updated.');
    }

    /** The current logo's media uuid, or null when none is set or the asset is gone. */
    private function logoUuid(): ?string
    {
        $mediaId = (int) ($this->manufacturer()['logo_media_id'] ?? 0);
        if ($mediaId <= 0) {
            return null;
        }

        $row = Database::connect()->table('media_assets')->select('uuid')
            ->where('id', $mediaId)->where('status', 'active')->where('deleted_at', null)
            ->get()->getRowArray();

        return $row['uuid'] ?? null;
    }
}
