<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use Config\Database;

/**
 * Admin\MediaController — read-only media browser. Vendors are folders; under a
 * vendor sit its shops (sub-folders) plus the vendor's own business files. Admin
 * can open any file (presigned GET). Guarded by `media.view` (webAuth at route).
 */
final class MediaController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $db      = Database::connect();
        $vendors = $db->table('vendors')->select('id, display_name')->where('deleted_at', null)
            ->orderBy('display_name', 'ASC')->get()->getResultArray();
        $vendorIds = array_map(static fn ($v) => (int) $v['id'], $vendors);

        // file counts: vendor-owned + roll up shop-owned to their vendor
        $repo        = service('mediaLibraryRepository');
        $vendorOwned = $repo->countByOwner('vendor', $vendorIds);
        $shops       = $vendorIds === [] ? [] : $db->table('shops')->select('id, vendor_id')->where('deleted_at', null)
            ->whereIn('vendor_id', $vendorIds)->get()->getResultArray();
        $shopOwned = $repo->countByOwner('shop', array_map(static fn ($s) => (int) $s['id'], $shops));
        $shopToVendor = [];
        foreach ($shops as $s) {
            $shopToVendor[(int) $s['id']] = (int) $s['vendor_id'];
        }
        $counts = [];
        foreach ($vendorIds as $vid) {
            $counts[$vid] = $vendorOwned[$vid] ?? 0;
        }
        foreach ($shopOwned as $sid => $c) {
            $vid = $shopToVendor[$sid] ?? 0;
            if ($vid) {
                $counts[$vid] = ($counts[$vid] ?? 0) + $c;
            }
        }

        return view('admin/media/index', [
            'title' => 'Media Library · Admin', 'pageTitle' => 'Media Library', 'active' => 'media',
            'userName' => session()->get('user_name') ?: 'User',
            'mode' => 'vendors', 'vendors' => $vendors, 'counts' => $counts,
        ]);
    }

    public function vendor(int $vendorId)
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $db     = Database::connect();
        $vendor = $db->table('vendors')->select('id, display_name')->where('id', $vendorId)->get()->getRowArray();
        if ($vendor === null) {
            return redirect()->to('admin/media')->with('error', 'Vendor not found.');
        }
        $repo  = service('mediaLibraryRepository');
        $shops = $db->table('shops')->select('id, name')->where('vendor_id', $vendorId)->where('deleted_at', null)
            ->orderBy('name', 'ASC')->get()->getResultArray();
        $shopCounts = $repo->countByOwner('shop', array_map(static fn ($s) => (int) $s['id'], $shops));

        return view('admin/media/index', [
            'title' => 'Media · ' . ($vendor['display_name'] ?? 'Vendor'), 'pageTitle' => 'Media Library', 'active' => 'media',
            'userName' => session()->get('user_name') ?: 'User',
            'mode' => 'vendor', 'vendor' => $vendor, 'shops' => $shops, 'shopCounts' => $shopCounts,
            'files' => $this->filterFiles($repo->listForOwner('vendor', $vendorId)),
            'fType' => (string) $this->request->getGet('type'), 'fQ' => trim((string) $this->request->getGet('q')),
        ]);
    }

    public function shop(int $shopId)
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $db   = Database::connect();
        $shop = $db->table('shops s')->select('s.id, s.name, s.vendor_id, v.display_name AS vendor_name')
            ->join('vendors v', 'v.id = s.vendor_id', 'left')->where('s.id', $shopId)->get()->getRowArray();
        if ($shop === null) {
            return redirect()->to('admin/media')->with('error', 'Shop not found.');
        }

        return view('admin/media/index', [
            'title' => 'Media · ' . ($shop['name'] ?? 'Shop'), 'pageTitle' => 'Media Library', 'active' => 'media',
            'userName' => session()->get('user_name') ?: 'User',
            'mode' => 'shop', 'shop' => $shop,
            'files' => $this->filterFiles(service('mediaLibraryRepository')->listForOwner('shop', $shopId)),
            'fType' => (string) $this->request->getGet('type'), 'fQ' => trim((string) $this->request->getGet('q')),
        ]);
    }

    /** Admin uploads file(s) into a vendor's or shop's media library (multipart). */
    public function upload(string $owner, int $ownerId)
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        if (! in_array($owner, ['vendor', 'shop'], true)) {
            return redirect()->to('admin/media')->with('error', 'Invalid upload target.');
        }
        $db     = Database::connect();
        $exists = $owner === 'vendor'
            ? $db->table('vendors')->where('id', $ownerId)->where('deleted_at', null)->countAllResults()
            : $db->table('shops')->where('id', $ownerId)->where('deleted_at', null)->countAllResults();
        if (! $exists) {
            return redirect()->to('admin/media')->with('error', ucfirst($owner) . ' not found.');
        }
        $uid  = (int) session()->get('user_id');
        $ok   = 0;
        $fail = [];
        foreach ((array) ($this->request->getFileMultiple('files') ?? []) as $file) {
            if ($file === null) {
                continue;
            }
            if (! $file->isValid()) {
                if ($file->getError() !== UPLOAD_ERR_NO_FILE) {
                    $fail[] = ($file->getName() ?: 'file') . ' — ' . $file->getErrorString();
                }
                continue;
            }
            $kind = str_starts_with((string) $file->getMimeType(), 'image/') ? 'image' : 'document';
            $res  = service('mediaService')->store($file, $owner, $ownerId, $uid, 'private', $kind);
            if (! empty($res['ok'])) {
                $ok++;
            } else {
                $fail[] = ($file->getName() ?: 'file') . ' — ' . ($res['reason'] ?? 'rejected');
            }
        }
        $msg = $ok . ' file(s) uploaded.' . ($fail !== [] ? ' ' . count($fail) . ' failed: ' . implode('; ', array_slice($fail, 0, 2)) : '');

        return redirect()->to('admin/media/' . $owner . '/' . $ownerId)->with($ok > 0 ? 'success' : 'error', $msg);
    }

    /** Filter a file list by ?type (image/pdf/video/doc) and ?q (filename search). */
    private function filterFiles(array $files): array
    {
        $type = (string) $this->request->getGet('type');
        $q    = trim((string) $this->request->getGet('q'));
        if ($type === '' && $q === '') {
            return $files;
        }

        return array_values(array_filter($files, static function ($f) use ($type, $q) {
            $mime = strtolower((string) ($f['mime'] ?? ''));
            $matchType = match ($type) {
                'image' => str_starts_with($mime, 'image/'),
                'pdf'   => $mime === 'application/pdf',
                'video' => str_starts_with($mime, 'video/'),
                'doc'   => (bool) preg_match('#(word|officedocument|excel|spreadsheet|presentation|powerpoint|csv|text)#', $mime),
                default => true,
            };
            if (! $matchType) {
                return false;
            }
            if ($q !== '' && stripos((string) ($f['original_name'] ?? '') . ' ' . (string) ($f['object_key'] ?? ''), $q) === false) {
                return false;
            }

            return true;
        }));
    }

    public function view(int $id)
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $m = service('mediaLibraryRepository')->findById($id);
        if ($m === null) {
            return redirect()->to('admin/media')->with('error', 'File not found.');
        }

        return redirect()->to(service('documentStorage')->presignGet((string) $m['object_key'], 'vendor/media/file'));
    }

    public function delete(int $id)
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $m = service('mediaLibraryRepository')->findById($id);
        if ($m === null) {
            return redirect()->to('admin/media')->with('error', 'File not found.');
        }
        service('documentStorage')->delete((string) ($m['object_key'] ?? ''));
        service('mediaLibraryRepository')->markDeleted($id, (int) session()->get('user_id'));

        $back = match ($m['owner_type'] ?? '') {
            'vendor' => 'admin/media/vendor/' . (int) $m['owner_id'],
            'shop'   => 'admin/media/shop/' . (int) $m['owner_id'],
            default  => 'admin/media',
        };

        return redirect()->to($back)->with('success', 'File deleted.');
    }

    private function guard()
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), 'media.view')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
