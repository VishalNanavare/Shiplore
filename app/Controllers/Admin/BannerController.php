<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseApiController;

/**
 * Admin\BannerController — home banner CRUD + image upload for the admin panel.
 *
 * Routes (all protected by webAuth filter from the admin group):
 *   GET  admin/banners            → list()       — JSON array of all banners
 *   POST admin/banners/store      → store()      — create + optional image upload
 *   POST admin/banners/{id}/update → update($id) — edit fields + optional new image
 *   POST admin/banners/{id}/delete → delete($id) — soft delete
 *   POST admin/banners/{id}/toggle → toggle($id) — active ↔ disabled
 *
 * Images are stored at public/uploads/banners/ and served as
 * {base_url}/uploads/banners/{filename}. Max 6 active home banners enforced.
 */
final class BannerController extends BaseApiController
{
    private const UPLOAD_DIR  = ROOTPATH . 'public/uploads/banners/';
    private const MAX_SIZE_KB = 2048;  // 2 MB file-size cap
    private const MIN_WIDTH   = 600;   // px — minimum for legible text on-device
    private const MIN_HEIGHT  = 300;   // px — enforces at least 2:1 aspect ratio
    private const ALLOWED     = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    private ?string $_uploadError = null;

    public function index()
    {
        $db = db_connect();
        return view('admin/banners/index', [
            'title'      => 'Banners · Admin',
            'pageTitle'  => 'Home Banners',
            'active'     => 'banners',
            'banners'    => service('bannerRepository')->list(),
            'categories' => $db->table('categories')->select('id, name, slug, level')
                ->where('status', 'active')->orderBy('path')->get()->getResultArray(),
            'brands'     => $db->table('brands')->select('id, name')
                ->where('status', 'active')->orderBy('name')->get()->getResultArray(),
        ]);
    }

    public function store()
    {
        $data = $this->_fields();
        if ($data === null) {
            return $this->failWith('VALIDATION', 'Title is required.');
        }

        $imageUrl = $this->_handleUpload();
        if ($imageUrl === false) {
            return $this->failWith('VALIDATION', $this->_uploadError ?? 'Invalid image file.');
        }
        if ($imageUrl !== null) {
            $data['image_url'] = $imageUrl;
        }

        $id = service('bannerRepository')->create($data, $this->_actorId());
        if ($id === null) {
            return $this->failWith('LIMIT', 'Maximum 6 active home banners allowed. Disable one first.');
        }

        return $this->created(['id' => $id]);
    }

    public function update($id = null)
    {
        $id = (int) $id;
        $banner = service('bannerRepository')->find($id);
        if ($banner === null) {
            return $this->failWith('NOT_FOUND', 'Banner not found.');
        }

        $data = $this->_fields();
        if ($data === null) {
            return $this->failWith('VALIDATION', 'Title is required.');
        }

        $imageUrl = $this->_handleUpload();
        if ($imageUrl === false) {
            return $this->failWith('VALIDATION', $this->_uploadError ?? 'Invalid image file.');
        }
        if ($imageUrl !== null) {
            // Remove old file only when replacing with a new upload.
            if (! empty($banner['image_url'])) {
                $this->_deleteFile($banner['image_url']);
            }
            $data['image_url'] = $imageUrl;
        }

        $ok = service('bannerRepository')->update($id, $data, $this->_actorId());
        if (! $ok) {
            return $this->failWith('LIMIT', 'Maximum 6 active home banners allowed. Disable one first.');
        }

        return $this->ok(['updated' => true]);
    }

    public function delete($id = null)
    {
        $id = (int) $id;
        $banner = service('bannerRepository')->find($id);
        if ($banner === null) {
            return $this->failWith('NOT_FOUND', 'Banner not found.');
        }

        service('bannerRepository')->delete($id, $this->_actorId());

        if (! empty($banner['image_url'])) {
            $this->_deleteFile($banner['image_url']);
        }

        return $this->ok(['deleted' => true]);
    }

    public function toggle($id = null)
    {
        $id = (int) $id;
        $banner = service('bannerRepository')->find($id);
        if ($banner === null) {
            return $this->failWith('NOT_FOUND', 'Banner not found.');
        }

        $newStatus = $banner['status'] === 'active' ? 'disabled' : 'active';
        $ok = service('bannerRepository')->update($id, ['status' => $newStatus], $this->_actorId());
        if (! $ok) {
            return $this->failWith('LIMIT', 'Maximum 6 active home banners allowed. Disable one first.');
        }

        return $this->ok(['status' => $newStatus]);
    }

    /** GET admin/banners/brands?category={slug} — brands with product counts in scope (for filter builder cascade). */
    public function brands()
    {
        $category = trim((string) $this->request->getGet('category'));
        $opts     = $category !== '' ? ['category' => $category] : [];

        return $this->ok(service('storeCatalogRepository')->computeBrandFacets($opts));
    }

    /** GET admin/banners/discount-tiers?category={slug}&brand={id} — discount tier distribution (for filter builder context). */
    public function discountTiers()
    {
        $category = trim((string) $this->request->getGet('category'));
        $brandId  = (int) $this->request->getGet('brand');
        $opts     = [];
        if ($category !== '') {
            $opts['category'] = $category;
        }
        if ($brandId > 0) {
            $opts['brand'] = $brandId;
        }

        return $this->ok(service('storeCatalogRepository')->computeDiscountTierFacets($opts));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Pull and validate the common scalar fields from the request body. */
    private function _fields(): ?array
    {
        $in = $this->input();

        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        return array_filter([
            'title'      => $title,
            'subtitle'   => isset($in['subtitle']) ? trim((string) $in['subtitle']) : null,
            'placement'  => in_array($in['placement'] ?? 'home', ['home', 'category', 'app_top'], true)
                                ? $in['placement']
                                : 'home',
            'target_url' => isset($in['target_url']) ? trim((string) $in['target_url']) : null,
            'sort_order' => isset($in['sort_order']) ? (int) $in['sort_order'] : null,
            'status'     => in_array($in['status'] ?? 'active', ['scheduled', 'active', 'disabled'], true)
                                ? $in['status']
                                : 'active',
            'starts_at'  => (! empty($in['starts_at'])) ? $in['starts_at'] : null,
            'ends_at'    => (! empty($in['ends_at'])) ? $in['ends_at'] : null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * Handle an optional file upload for the banner image.
     *
     * Validates: file present → MIME type → file size → image dimensions.
     * Sets $this->_uploadError with a human-readable message before returning false.
     *
     * @return string|null|false  string = new public URL, null = no file sent, false = validation failed
     */
    private function _handleUpload(): string|null|false
    {
        $this->_uploadError = null;

        $file = $this->request->getFile('image');
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (! $file->isValid()) {
            $this->_uploadError = 'Image upload failed (error ' . $file->getError() . ').';
            return false;
        }
        if (! in_array($file->getMimeType(), self::ALLOWED, true)) {
            $this->_uploadError = 'Unsupported image type "' . $file->getMimeType() . '". Accepted formats: JPEG, PNG, WebP, GIF.';
            return false;
        }
        if ($file->getSizeByUnit('kb') > self::MAX_SIZE_KB) {
            $this->_uploadError = 'Image is too large (' . round($file->getSizeByUnit('kb') / 1024, 1) . ' MB). Maximum allowed size is 2 MB.';
            return false;
        }

        if (! is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }

        $name = $file->getRandomName();
        $file->move(self::UPLOAD_DIR, $name);
        $path = self::UPLOAD_DIR . $name;

        // Validate pixel dimensions after moving (GD/Exif-independent, works on all PHP installs).
        $info = @getimagesize($path);
        if ($info === false) {
            @unlink($path);
            $this->_uploadError = 'Could not read image dimensions. Make sure the file is a valid image.';
            return false;
        }
        [$w, $h] = $info;
        if ($w < self::MIN_WIDTH || $h < self::MIN_HEIGHT) {
            @unlink($path);
            $this->_uploadError = "Image too small ({$w}×{$h} px). Minimum is " . self::MIN_WIDTH . '×' . self::MIN_HEIGHT . " px. Recommended: 1200×600 px (2:1 ratio) for best display quality.";
            return false;
        }

        return base_url('uploads/banners/' . $name);
    }

    /** Remove an old banner image from disk (if it lives in our uploads dir). */
    private function _deleteFile(string $url): void
    {
        $prefix = base_url('uploads/banners/');
        if (str_starts_with($url, $prefix)) {
            $path = self::UPLOAD_DIR . basename($url);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function _actorId(): int
    {
        // webAuth routes: actor comes from session (not JWT sub).
        return (int) (session()->get('user_id') ?? 0);
    }
}
