<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Database;

/**
 * Admin\VendorTypeMismatchController — monitors and repairs products whose
 * category falls outside their vendor's business-type allowed-category map.
 * A mismatch arises when a vendor's business_type_id is changed after products
 * were assigned. All bulk actions skip products that have order_items records.
 */
final class VendorTypeMismatchController extends BaseController
{
    /** Summary list of vendors with at least one mismatched product. */
    public function index(): string|RedirectResponse
    {
        if ($denied = $this->guard('vendor.view')) {
            return $denied;
        }

        $req     = $this->request;
        $perRaw  = (string) $req->getGet('per_page');
        $perPage = in_array((int) $perRaw, [25, 50, 100], true) ? (int) $perRaw : 25;
        $page    = max(1, (int) $req->getGet('page'));

        $db  = Database::connect();
        $sql = "SELECT v.id, v.display_name, bt.name AS business_type,
                       COUNT(DISTINCT p.id) AS mismatch_count,
                       GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS sample_categories
                FROM vendors v
                JOIN business_types bt ON bt.id = v.business_type_id
                JOIN products p ON p.vendor_id = v.id AND p.deleted_at IS NULL
                JOIN categories c ON c.id = p.category_id
                LEFT JOIN business_type_category_map m
                       ON m.business_type_id = v.business_type_id AND m.category_id = p.category_id
                WHERE v.deleted_at IS NULL
                  AND v.business_type_id IS NOT NULL
                  AND m.category_id IS NULL
                GROUP BY v.id
                HAVING mismatch_count > 0
                ORDER BY mismatch_count DESC";

        $all   = $db->query($sql)->getResultArray();
        $total = count($all);
        $rows  = array_slice($all, ($page - 1) * $perPage, $perPage);

        return view('admin/vendors/type-mismatches', [
            'title'     => 'Business-Type Mismatches · Admin',
            'pageTitle' => 'Business-Type Mismatches',
            'active'    => 'vendors',
            'userName'  => session()->get('user_name') ?: 'User',
            'vendors'   => $rows,
            'total'     => $total,
            'page'      => $page,
            'perPage'   => $perPage,
        ]);
    }

    /** Per-vendor drill-down: mismatched products + reassign form. */
    public function show(int $vendorId): string|RedirectResponse
    {
        if ($denied = $this->guard('vendor.view')) {
            return $denied;
        }

        $db     = Database::connect();
        $vendor = $db->table('vendors v')
            ->select('v.id, v.display_name, v.business_type_id, bt.name AS business_type')
            ->join('business_types bt', 'bt.id = v.business_type_id', 'left')
            ->where('v.id', $vendorId)->where('v.deleted_at', null)
            ->get()->getRowArray();

        if ($vendor === null) {
            return redirect()->to('admin/vendors/type-mismatches')->with('error', 'Vendor not found.');
        }

        if (empty($vendor['business_type_id'])) {
            return redirect()->to('admin/vendors/type-mismatches')
                ->with('error', 'This vendor has no business type assigned.');
        }

        // Products whose category is NOT in the vendor's allowed-category map.
        $products = $db->query(
            "SELECT p.id, p.name, p.sku, c.id AS category_id, c.name AS category_name,
                    pc.name AS parent_category,
                    m_correct.business_type_id AS correct_type_id,
                    bt_correct.name AS correct_type,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.product_id = p.id) AS order_count
             FROM products p
             JOIN categories c ON c.id = p.category_id
             LEFT JOIN categories pc ON pc.id = c.parent_id
             LEFT JOIN business_type_category_map m_src
                    ON m_src.business_type_id = ? AND m_src.category_id = p.category_id
             LEFT JOIN business_type_category_map m_correct ON m_correct.category_id = p.category_id
             LEFT JOIN business_types bt_correct ON bt_correct.id = m_correct.business_type_id
             WHERE p.vendor_id = ? AND p.deleted_at IS NULL AND m_src.category_id IS NULL
             ORDER BY c.name, p.name",
            [$vendor['business_type_id'], $vendorId]
        )->getResultArray();

        // Target vendors per correct business type (for the reassign dropdown).
        $correctTypeIds = array_unique(array_filter(array_column($products, 'correct_type_id')));
        $targetVendors  = [];
        if ($correctTypeIds !== []) {
            $targetVendors = $db->query(
                'SELECT v.id, v.display_name, v.business_type_id, bt.name AS business_type
                 FROM vendors v
                 JOIN business_types bt ON bt.id = v.business_type_id
                 WHERE v.business_type_id IN (' . implode(',', array_map('intval', $correctTypeIds)) . ')
                   AND v.id != ? AND v.deleted_at IS NULL AND v.status IN (\'active\',\'approved\')
                 ORDER BY bt.name, v.display_name',
                [$vendorId]
            )->getResultArray();
        }

        // Group target vendors by business_type_id for fast lookup in view.
        $targetByType = [];
        foreach ($targetVendors as $tv) {
            $targetByType[(int) $tv['business_type_id']][] = $tv;
        }

        return view('admin/vendors/product-mismatches', [
            'title'        => 'Product Mismatches · ' . ($vendor['display_name'] ?? ''),
            'pageTitle'    => 'Product Mismatches: ' . esc($vendor['display_name'] ?? ''),
            'active'       => 'vendors',
            'userName'     => session()->get('user_name') ?: 'User',
            'vendor'       => $vendor,
            'products'     => $products,
            'targetByType' => $targetByType,
        ]);
    }

    /** POST — move selected products to a chosen target vendor. */
    public function reassign(int $vendorId): RedirectResponse
    {
        if ($denied = $this->guard('vendor.update')) {
            return $denied;
        }

        $db     = Database::connect();
        $vendor = $db->table('vendors')->where('id', $vendorId)->where('deleted_at', null)->get()->getRowArray();
        if ($vendor === null) {
            return redirect()->to('admin/vendors/type-mismatches')->with('error', 'Vendor not found.');
        }

        $rawIds        = $this->request->getPost('product_ids');
        $targetId      = (int) $this->request->getPost('target_vendor_id');
        $productIds    = is_array($rawIds) ? array_map('intval', $rawIds) : [];

        if ($productIds === [] || $targetId <= 0) {
            return redirect()->back()->with('error', 'Select at least one product and a target vendor.');
        }

        $targetVendor = $db->table('vendors v')
            ->select('v.id, v.business_type_id')
            ->where('v.id', $targetId)->where('v.deleted_at', null)
            ->get()->getRowArray();

        if ($targetVendor === null) {
            return redirect()->back()->with('error', 'Target vendor not found.');
        }

        // Filter: skip products with orders; also verify category is allowed in target.
        $moved   = 0;
        $skipped = 0;

        $db->transStart();
        foreach ($productIds as $pid) {
            $product = $db->table('products p')
                ->select('p.id, p.category_id')
                ->where('p.id', $pid)->where('p.vendor_id', $vendorId)->where('p.deleted_at', null)
                ->get()->getRowArray();
            if ($product === null) {
                $skipped++;
                continue;
            }
            // Has orders?
            $hasOrders = $db->table('order_items')->where('product_id', $pid)->countAllResults() > 0;
            if ($hasOrders) {
                $skipped++;
                continue;
            }
            // Target business type allows this category?
            $allowed = $db->table('business_type_category_map')
                ->where('business_type_id', $targetVendor['business_type_id'])
                ->where('category_id', $product['category_id'])
                ->countAllResults() > 0;
            if (! $allowed) {
                $skipped++;
                continue;
            }
            $db->table('products')->where('id', $pid)->update([
                'vendor_id'  => $targetId,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $moved++;
        }
        $db->transComplete();

        // $moved is counted in PHP as the loop runs, so it describes what was
        // ATTEMPTED. If the transaction rolled back, nothing moved and reporting the
        // count is simply false — the admin sees "14 product(s) moved" over an
        // unchanged table.
        if (! $db->transStatus()) {
            return redirect()->to('admin/vendors/' . $vendorId . '/product-mismatches')
                ->with('error', 'Could not move the products. Nothing was changed.');
        }

        $msg = "{$moved} product(s) moved.";
        if ($skipped > 0) {
            $msg .= " {$skipped} skipped (have orders or category not allowed in target).";
        }

        return redirect()->to('admin/vendors/' . $vendorId . '/product-mismatches')
            ->with($moved > 0 ? 'success' : 'warning', $msg);
    }

    /**
     * POST — bulk auto-fix: for every mismatched product with no orders, move
     * it to the lowest-id active vendor whose business type allows its category.
     */
    public function autoFix(): RedirectResponse
    {
        if ($denied = $this->guard('vendor.update')) {
            return $denied;
        }

        $db = Database::connect();

        // Build the fix map in PHP to keep SQL simple and safe.
        $mismatched = $db->query(
            "SELECT p.id AS product_id, p.category_id
             FROM products p
             JOIN vendors v ON v.id = p.vendor_id AND v.business_type_id IS NOT NULL AND v.deleted_at IS NULL
             LEFT JOIN business_type_category_map m_src
                    ON m_src.business_type_id = v.business_type_id AND m_src.category_id = p.category_id
             WHERE p.deleted_at IS NULL AND m_src.category_id IS NULL
               AND NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.product_id = p.id)"
        )->getResultArray();

        if ($mismatched === []) {
            return redirect()->to('admin/vendors/type-mismatches')->with('success', 'No mismatched products without orders found.');
        }

        // Cache target vendor per category_id.
        $targetCache = [];
        $moved       = 0;
        $noTarget    = 0;

        $db->transStart();
        foreach ($mismatched as $row) {
            $catId = (int) $row['category_id'];
            if (! array_key_exists($catId, $targetCache)) {
                $t = $db->query(
                    "SELECT v.id FROM vendors v
                     JOIN business_type_category_map m ON m.business_type_id = v.business_type_id
                     WHERE m.category_id = ? AND v.deleted_at IS NULL AND v.status IN ('active','approved')
                     ORDER BY v.id ASC LIMIT 1",
                    [$catId]
                )->getRowArray();
                $targetCache[$catId] = $t ? (int) $t['id'] : null;
            }
            $targetId = $targetCache[$catId];
            if ($targetId === null) {
                $noTarget++;
                continue;
            }
            $db->table('products')->where('id', (int) $row['product_id'])->update([
                'vendor_id'  => $targetId,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $moved++;
        }
        $db->transComplete();

        // Same as bulkMove above: $moved counts attempts, not commits.
        if (! $db->transStatus()) {
            return redirect()->to('admin/vendors/type-mismatches')
                ->with('error', 'Auto-fix failed. Nothing was changed.');
        }

        $msg = "Auto-fix complete: {$moved} product(s) moved.";
        if ($noTarget > 0) {
            $msg .= " {$noTarget} left in place (no matching vendor found for their category).";
        }

        return redirect()->to('admin/vendors/type-mismatches')->with('success', $msg);
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
