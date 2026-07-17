<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * ProductMediaActions — shared media management (gallery reorder / set-primary /
 * remove, and document upload/delete) for the Admin and Vendor product editors.
 * The host controller supplies product resolution (scoped or not), the URL
 * prefix and the access guard.
 */
trait ProductMediaActions
{
    /** @return array<string,mixed>|null product if the caller may manage it */
    abstract protected function resolveProduct(int $productId): ?array;

    abstract protected function mediaPrefix(): string;

    abstract protected function mediaGuard(): ?RedirectResponse;

    /** AJAX: persist the gallery order. */
    public function reorder(int $productId)
    {
        if ($this->mediaGuard()) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }
        if ($this->resolveProduct($productId) === null) {
            return $this->response->setStatusCode(404)->setJSON(['ok' => false]);
        }
        $order = array_map('intval', (array) $this->request->getPost('order'));
        service('mediaRepository')->reorder($productId, $order, (int) session()->get('user_id'));

        return $this->response->setJSON(['ok' => true, 'csrf' => csrf_hash()]);
    }

    /** AJAX: the product vendor's reusable image library + which are already attached. */
    public function library(int $productId)
    {
        if ($this->mediaGuard()) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }
        $product = $this->resolveProduct($productId);
        if ($product === null) {
            return $this->response->setStatusCode(404)->setJSON(['ok' => false]);
        }
        $vendorId = (int) ($product['vendor_id'] ?? 0);
        $attached = array_map('intval', array_column(service('mediaRepository')->forProduct($productId), 'media_id'));

        $images = array_map(
            static fn ($im) => ['id' => (int) $im['id'], 'url' => site_url('media/' . $im['uuid'])],
            service('mediaRepository')->vendorImages($vendorId)
        );

        return $this->response->setJSON(['ok' => true, 'images' => $images, 'attached' => $attached, 'csrf' => csrf_hash()]);
    }

    /** AJAX: attach already-existing library images to this product (multi-select). */
    public function attachLibrary(int $productId)
    {
        if ($this->mediaGuard()) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }
        $product = $this->resolveProduct($productId);
        if ($product === null) {
            return $this->response->setStatusCode(404)->setJSON(['ok' => false]);
        }
        $vendorId = (int) ($product['vendor_id'] ?? 0);
        $repo     = service('mediaRepository');
        $uid      = (int) session()->get('user_id');
        $n        = 0;
        foreach (array_values(array_filter(array_map('intval', (array) $this->request->getPost('media_ids')))) as $mid) {
            if (! $repo->vendorOwnsImage($vendorId, $mid) || $repo->isAttached($productId, $mid)) {
                continue;
            }
            $repo->attachToProduct($productId, $mid, ! $repo->hasPrimary($productId), $uid);
            $n++;
        }

        return $this->response->setJSON(['ok' => true, 'attached' => $n, 'csrf' => csrf_hash()]);
    }

    /** AJAX: upload new image(s) into the vendor library AND attach to this product. */
    public function uploadImage(int $productId)
    {
        if ($this->mediaGuard()) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'csrf' => csrf_hash()]);
        }
        $product = $this->resolveProduct($productId);
        if ($product === null) {
            return $this->response->setStatusCode(404)->setJSON(['ok' => false, 'csrf' => csrf_hash()]);
        }
        $vendorId = (int) ($product['vendor_id'] ?? 0);
        $repo     = service('mediaRepository');
        $uid      = (int) session()->get('user_id');
        $files    = $this->request->getFileMultiple('image') ?? [];
        $items    = [];
        $errors   = [];
        foreach ((array) $files as $file) {
            if ($file === null) {
                continue;
            }
            if (! $file->isValid()) {
                if ($file->getError() !== UPLOAD_ERR_NO_FILE) {
                    $errors[] = ($file->getName() ?: 'image') . ' — ' . $file->getErrorString();
                }
                continue;
            }
            // Stored as a VENDOR asset so it is reusable across products, then linked here.
            $res = service('mediaService')->store($file, 'vendor', $vendorId, $uid, 'public');
            if (! empty($res['ok'])) {
                $repo->attachToProduct($productId, (int) $res['id'], ! $repo->hasPrimary($productId), $uid);
                $items[] = ['id' => (int) $res['id'], 'uuid' => $res['uuid']];
            } else {
                $errors[] = ($file->getName() ?: 'image') . ' — ' . ($res['reason'] ?? 'rejected');
            }
        }

        return $this->response->setJSON(['ok' => $items !== [], 'items' => $items, 'errors' => $errors, 'csrf' => csrf_hash()]);
    }

    public function setPrimary(int $productId)
    {
        return $this->mediaMutate($productId, fn () => service('mediaRepository')->setPrimaryImage($productId, (int) $this->request->getPost('media_id'), (int) session()->get('user_id')), 'Primary image set.');
    }

    public function removeImage(int $productId)
    {
        return $this->mediaMutate($productId, fn () => service('mediaRepository')->detach($productId, (int) $this->request->getPost('media_id'), (int) session()->get('user_id')), 'Image removed.');
    }

    public function uploadDoc(int $productId)
    {
        return $this->mediaMutate($productId, function () use ($productId) {
            $file = $this->request->getFile('document');
            if ($file === null || ! $file->isValid()) {
                return false;
            }
            $res = service('mediaService')->store($file, 'product_doc', $productId, (int) session()->get('user_id'), 'public', 'document');
            if (empty($res['ok'])) {
                session()->setFlashdata('error', $res['reason'] ?? 'Upload failed.');

                return false;
            }

            return service('mediaRepository')->addDocument($productId, (int) $res['id'], (string) $this->request->getPost('doc_type'), (string) $this->request->getPost('doc_title'), (int) session()->get('user_id'));
        }, 'Document uploaded.');
    }

    public function deleteDoc(int $productId, int $docId)
    {
        return $this->mediaMutate($productId, fn () => service('mediaRepository')->deleteDocument($productId, $docId, (int) session()->get('user_id')), 'Document removed.');
    }

    /** Runs $fn after guard + ownership; returns JSON for AJAX, else redirects to edit. */
    private function mediaMutate(int $productId, callable $fn, string $okMsg)
    {
        if ($denied = $this->mediaGuard()) {
            return $this->request->isAJAX() ? $this->response->setStatusCode(403)->setJSON(['ok' => false]) : $denied;
        }
        if ($this->resolveProduct($productId) === null) {
            return $this->request->isAJAX()
                ? $this->response->setStatusCode(404)->setJSON(['ok' => false])
                : redirect()->to($this->mediaPrefix() . '/products')->with('error', 'Product not found.');
        }
        $ok = (bool) $fn();
        if ($this->request->isAJAX()) {
            return $this->response->setJSON(['ok' => $ok, 'csrf' => csrf_hash()]);
        }

        return redirect()->to($this->mediaPrefix() . '/products/' . $productId . '/edit')->with($ok ? 'success' : 'error', $ok ? $okMsg : (session()->getFlashdata('error') ?: 'Could not complete that action.'));
    }
}
