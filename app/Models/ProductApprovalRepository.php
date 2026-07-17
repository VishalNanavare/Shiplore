<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Workflow\StatusMachine;
use Config\Database;
use Throwable;

/**
 * ProductApprovalRepository — admin review queue for vendor-submitted products.
 * Lists products awaiting review (submitted / under_review) joined with vendor
 * and category, and performs approve / reject transitions: it updates
 * products.status, the linked product_approvals row (reviewer, decision time)
 * and appends a product_approval_history entry — all in one transaction.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md §3 (Product Approvals)
 */
final class ProductApprovalRepository
{
    /** Statuses that make up the pending review queue. */
    private const PENDING = ['submitted', 'under_review'];

    /**
     * Review-queue list with filters + pagination.
     * @param array<string,mixed> $f keys: status, vendor_id, category_id, q, limit, offset
     * @return list<array<string,mixed>>
     */
    public function list(array $f = []): array
    {
        $b = Database::connect()->table('products p')
            ->select('p.id, p.title, p.slug, p.status, p.approval_id, p.created_at, v.display_name AS vendor, c.name AS category')
            ->join('vendors v', 'v.id = p.vendor_id', 'left')
            ->join('categories c', 'c.id = p.category_id', 'left');
        $this->applyFilters($b, $f);
        $b->orderBy('p.created_at', 'ASC');

        $limit = (int) ($f['limit'] ?? 20);
        if ($limit > 0) {
            $b->limit($limit, (int) ($f['offset'] ?? 0));
        }

        return $b->get()->getResultArray();
    }

    /** Count matching the same filters (for pagination). @param array<string,mixed> $f */
    public function countList(array $f = []): int
    {
        $b = Database::connect()->table('products p');
        $this->applyFilters($b, $f);

        return $b->countAllResults();
    }

    /** @param array<string,mixed> $f */
    private function applyFilters(object $b, array $f): void
    {
        $b->where('p.deleted_at', null);
        $status = (string) ($f['status'] ?? '');
        if ($status !== '') {
            $b->where('p.status', $status);
        } else {
            $b->whereIn('p.status', self::PENDING);   // default = the pending review queue
        }
        if (! empty($f['vendor_id'])) {
            $b->where('p.vendor_id', (int) $f['vendor_id']);
        }
        if (! empty($f['category_id'])) {
            $b->where('p.category_id', (int) $f['category_id']);
        }
        if (isset($f['q']) && $f['q'] !== '') {
            $b->groupStart()->like('p.title', $f['q'])->orLike('p.slug', $f['q'])->groupEnd();
        }
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('products')
            ->select('id, status, approval_id')
            ->where('id', $id)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function approve(int $id, int $actorId): bool
    {
        return $this->transition($id, 'approved', $actorId, null);
    }

    public function reject(int $id, int $actorId, ?string $reason = null): bool
    {
        return $this->transition($id, 'rejected', $actorId, $reason);
    }

    /** Vendor submits a draft/rejected/unpublished product for review (creates an approval row if needed). */
    public function submit(int $id, int $actorId): bool
    {
        $product = $this->findById($id);
        if ($product === null) {
            return false;
        }
        if (($product['approval_id'] ?? null) === null) {
            $db = Database::connect();
            $db->table('product_approvals')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'product_id' => $id, 'submitted_by' => $actorId,
                'status' => 'submitted', 'created_by' => $actorId,
            ]);
            $db->table('products')->where('id', $id)->update(['approval_id' => (int) $db->insertID()]);
        }

        return $this->transition($id, 'submitted', $actorId, null);
    }

    /** Admin sends a submitted/under-review product back to the vendor to fix. */
    public function requestChanges(int $id, int $actorId, ?string $reason = null): bool
    {
        return $this->transition($id, 'draft', $actorId, $reason);
    }

    /** Admin makes an approved/unpublished product live on the storefront. */
    public function publish(int $id, int $actorId): bool
    {
        $ok = $this->transition($id, 'published', $actorId, null);
        if ($ok) {
            // Going live with no shop listing would make it invisible on the
            // location-scoped storefront — guarantee at least one shop.
            service('adminProductRepository')->ensureListedInVendorShops($id);
        }

        return $ok;
    }

    /** Admin takes a published product off the storefront (suspend). */
    public function unpublish(int $id, int $actorId, ?string $reason = null): bool
    {
        return $this->transition($id, 'unpublished', $actorId, $reason);
    }

    /**
     * Move a product to $toStatus, mirror it onto its product_approvals row and
     * append history. Atomic; returns false on any failure (fail-safe).
     */
    private function transition(int $id, string $toStatus, int $actorId, ?string $reason): bool
    {
        $product = $this->findById($id);
        if ($product === null) {
            return false;
        }
        $from = (string) ($product['status'] ?? '');
        // reject illegal lifecycle moves (e.g. published -> submitted-skipping, draft -> approved)
        if (! StatusMachine::canProduct($from, $toStatus)) {
            return false;
        }

        $db = Database::connect();
        $db->transBegin();

        try {
            $now = date('Y-m-d H:i:s');

            $db->table('products')->where('id', $id)->update([
                'status'     => $toStatus,
                'updated_by' => $actorId,
            ]);

            $approvalId = $product['approval_id'] ?? null;
            if ($approvalId !== null) {
                $db->table('product_approvals')->where('id', $approvalId)->update([
                    'status'      => $toStatus,
                    'reviewer_id' => $actorId,
                    'decided_at'  => $now,
                    'updated_by'  => $actorId,
                ]);

                $db->table('product_approval_history')->insert([
                    'product_approval_id' => $approvalId,
                    'product_id'          => $id,
                    'from_status'         => $from,
                    'to_status'           => $toStatus,
                    'actor_id'            => $actorId,
                    'reason'              => $reason !== null ? mb_substr($reason, 0, 191) : null,
                ]);
            }

            $db->transComplete();
            $ok = $db->transStatus();

            // Catalog membership changed (to/from 'published') → mark the storefront
            // facet counts dirty so facets:refresh recomputes them in the background.
            if ($ok && ($toStatus === 'published' || $from === 'published')) {
                $slug = $db->table('categories c')->select('c.slug')
                    ->join('products p', 'p.category_id = c.id')
                    ->where('p.id', $id)->get()->getRowArray()['slug'] ?? null;
                service('facetCache')->invalidate($slug);
            }

            return $ok;
        } catch (Throwable) {
            $db->transRollback();

            return false;
        }
    }

    /** @return list<array<string,mixed>> approval history for a product (newest first) */
    public function history(int $productId): array
    {
        return Database::connect()->table('product_approval_history h')
            ->select('h.from_status, h.to_status, h.reason, h.created_at, u.name AS actor')
            ->join('users u', 'u.id = h.actor_id', 'left')
            ->where('h.product_id', $productId)->orderBy('h.id', 'DESC')->limit(50)
            ->get()->getResultArray();
    }
}
