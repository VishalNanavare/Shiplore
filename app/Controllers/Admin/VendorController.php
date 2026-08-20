<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\VendorController — vendor management (Phase 6 admin). Lists vendors and
 * approves/rejects them. Session-guarded by the `webAuth` filter; each action is
 * RBAC-checked against the resolved ScopeContext via PolicyEngine; POSTs are
 * CSRF-protected at the route.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md §2,§3
 */
final class VendorController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('vendor.view')) {
            return $denied;
        }

        $req     = $this->request;
        $status  = trim((string) $req->getGet('status'));
        $q       = trim((string) $req->getGet('q'));
        $type    = trim((string) $req->getGet('type'));
        $perRaw  = (string) $req->getGet('per_page');
        $perPage = in_array((int) $perRaw, [25, 50, 100, 200], true) ? (int) $perRaw : 50;
        $page    = max(1, (int) $req->getGet('page'));

        // Hard-scoped to vendors. Manufacturers share this table under
        // party_type='manufacturer' and have their own screens — they must never appear
        // here unlabelled, and this screen's shop/settlement panels are meaningless for them.
        $f     = ['status' => $status ?: null, 'q' => $q, 'type' => $type ?: null, 'party_type' => 'vendor'];
        $repo  = service('vendorRepository');
        $total = $repo->countList($f);
        $f['limit']  = $perPage;
        $f['offset'] = ($page - 1) * $perPage;

        return view('admin/vendors/index', [
            'title'     => 'Vendors · Admin',
            'pageTitle' => 'Vendors',
            'active'    => 'vendors',
            'userName'  => session()->get('user_name') ?: 'User',
            'vendors'   => $repo->list($f),
            'total'     => $total,
            'page'      => $page,
            'perPage'   => $perPage,
            'filters'   => ['status' => $status, 'q' => $q, 'type' => $type],
        ]);
    }

    public function new()
    {
        if ($denied = $this->guard('vendor.create')) {
            return $denied;
        }

        return $this->form(null);
    }

    /** Read-only vendor detail/info panel (with the "Go to Vendor Portal" action). */
    public function show(int $id)
    {
        if ($denied = $this->guard('vendor.view')) {
            return $denied;
        }
        $vendor = service('vendorRepository')->find($id);
        if ($vendor === null) {
            return redirect()->to('admin/vendors')->with('error', 'Vendor not found.');
        }
        if ($wrong = $this->rejectManufacturer($vendor, $id)) {
            return $wrong;
        }

        $db   = \Config\Database::connect();
        $more = $db->table('vendors v')
            ->select('v.pan, v.state_code, v.gstin_status, v.is_composition, v.payout_cycle, v.created_at, v.updated_at,
                      u.name AS owner_name, u.status AS owner_status, u.last_login_at,
                      bt.name AS business_type, m.uuid AS logo_uuid')
            ->join('users u', 'u.id = v.owner_user_id', 'left')
            ->join('business_types bt', 'bt.id = v.business_type_id', 'left')
            ->join('media_assets m', 'm.id = v.logo_media_id AND m.status = \'active\'', 'left')
            ->where('v.id', $id)->get()->getRowArray() ?? [];
        $vendor = array_merge($vendor, $more);

        $docs = $db->table('vendor_documents vd')
            ->select('vd.doc_type, vd.status, vd.expires_at, vd.created_at, ma.uuid')
            ->join('media_assets ma', 'ma.id = vd.media_id', 'left')
            ->where('vd.vendor_id', $id)->where('vd.deleted_at', null)
            ->orderBy('vd.id', 'DESC')->get()->getResultArray();

        $settlements = service('vendorSettlementRepository')->list($id, 1);

        $btypeId         = (int) ($vendor['business_type_id'] ?? 0);
        $allowedCategories = $btypeId > 0 ? service('businessTypeRepository')->categoriesFor($btypeId) : [];

        return view('admin/vendors/show', [
            'title'             => 'Vendor · ' . ($vendor['display_name'] ?? ''),
            'pageTitle'         => $vendor['display_name'] ?? 'Vendor',
            'active'            => 'vendors',
            'userName'          => session()->get('user_name') ?: 'User',
            'vendor'            => $vendor,
            'metrics'           => service('vendorDashboardRepository')->metrics($id),
            'shops'             => service('vendorShopRepository')->list($id),
            'gst'               => service('vendorGstRepository')->summary($id),
            'bank'              => service('vendorBankAccountRepository')->defaultForVendor($id),
            'settlement'        => $settlements[0] ?? null,
            'recentOrders'      => service('vendorDashboardRepository')->recentOrders($id, 8),
            'docs'              => $docs,
            'allowedCategories' => $allowedCategories,
        ]);
    }

    public function edit(int $id)
    {
        if ($denied = $this->guard('vendor.settings.manage')) {
            return $denied;
        }
        $vendor = service('vendorRepository')->find($id);
        if ($vendor === null) {
            return redirect()->to('admin/vendors')->with('error', 'Vendor not found.');
        }
        if ($wrong = $this->rejectManufacturer($vendor, $id)) {
            return $wrong;
        }

        return $this->form($vendor);
    }

    public function store(): RedirectResponse
    {
        if ($denied = $this->guard('vendor.create')) {
            return $denied;
        }

        $in    = $this->vendorInput();
        $errors = $this->validateVendor($in, true);
        if ($errors !== []) {
            return redirect()->back()->withInput()->with('error', implode(' ', $errors));
        }

        $reg = service('registrationRepository');
        if ($reg->emailExists($in['email'])) {
            return redirect()->back()->withInput()->with('error', 'Email already registered.');
        }
        if ($reg->mobileExists($in['mobile'])) {
            return redirect()->back()->withInput()->with('error', 'Mobile already registered.');
        }
        if ($in['gstin'] !== null && $reg->gstinExists($in['gstin'])) {
            return redirect()->back()->withInput()->with('error', 'GSTIN already registered.');
        }

        $vendorId = $reg->createVendorWithShop($in);
        if ($vendorId === null) {
            return redirect()->back()->withInput()->with('error', 'Could not create vendor.');
        }

        return redirect()->to('admin/vendors')->with('success', 'Vendor created with owner login and first shop.');
    }

    public function update(int $id): RedirectResponse
    {
        if ($denied = $this->guard('vendor.settings.manage')) {
            return $denied;
        }
        $repo   = service('vendorRepository');
        $vendor = $repo->find($id);
        if ($vendor === null) {
            return redirect()->to('admin/vendors')->with('error', 'Vendor not found.');
        }
        // Also guarded here, not just in edit() — otherwise a direct POST bypasses it.
        if ($wrong = $this->rejectManufacturer($vendor, $id)) {
            return $wrong;
        }
        $legal   = trim((string) $this->request->getPost('legal_name'));
        $display = trim((string) $this->request->getPost('display_name'));
        $btype   = (int) $this->request->getPost('business_type_id');
        if ($legal === '' || $display === '' || $btype <= 0) {
            return redirect()->back()->withInput()->with('error', 'Legal name, display name and business type are required.');
        }
        $ok = $repo->update($id, [
            'legal_name' => $legal, 'display_name' => $display, 'business_type_id' => $btype,
            'pan'                => strtoupper(trim((string) $this->request->getPost('pan'))),
            'state_code'         => strtoupper(trim((string) $this->request->getPost('state_code'))),
            'commission_plan_id' => (int) $this->request->getPost('commission_plan_id'),
            'payout_cycle'       => trim((string) $this->request->getPost('payout_cycle')) ?: 'weekly',
            'is_composition'     => (bool) $this->request->getPost('is_composition'),
        ], (int) session()->get('user_id'));
        if (! $ok) {
            return redirect()->back()->withInput()->with('error', 'Vendor could not be updated — check the payout cycle.');
        }

        // Warn if existing products are now in disallowed categories for this business type.
        $allowedIds = array_column((new \App\Models\AdminProductRepository())->allowedCategories($id), 'id');
        if ($allowedIds !== []) {
            $conflicts = \Config\Database::connect()->table('products')
                ->where('vendor_id', $id)->where('deleted_at', null)
                ->whereNotIn('category_id', $allowedIds)->countAllResults();
            if ($conflicts > 0) {
                session()->setFlashdata('warning', $conflicts . ' product(s) are in categories not allowed by the new business type. Review them under this vendor\'s Products tab.');
            }
        }

        // Optional logo upload — stored through MediaService (-> S3/test_s3).
        $logo = $this->request->getFile('logo');
        if ($logo !== null && $logo->isValid()) {
            $res = service('mediaService')->store($logo, 'vendor', $id, (int) session()->get('user_id'), 'public', 'image');
            if ($res['ok'] ?? false) {
                \Config\Database::connect()->table('vendors')->where('id', $id)->update(['logo_media_id' => (int) $res['id']]);
            } else {
                return redirect()->to('admin/vendors')->with('error', $res['reason'] ?? 'Vendor saved, but the logo upload failed.');
            }
        }

        return redirect()->to('admin/vendors/' . $id)->with('success', 'Vendor updated.');
    }

    /** @param array<string,mixed>|null $vendor */
    private function form(?array $vendor): string
    {
        try {
            $mapsKey = trim((string) (service('integrationRepository')->config('google_maps')['browser_key'] ?? ''));
        } catch (\Throwable) {
            $mapsKey = '';
        }

        $logoUuid        = null;
        $commissionPlans = [];
        if ($vendor !== null) {
            $row = \Config\Database::connect()->table('vendors v')
                ->select('m.uuid')->join('media_assets m', 'm.id = v.logo_media_id', 'left')
                ->where('v.id', (int) $vendor['id'])->where('m.status', 'active')->get()->getRowArray();
            $logoUuid        = $row['uuid'] ?? null;
            $commissionPlans = service('commissionRepository')->list('active');

            // The select must never silently drop the vendor's current plan: if it was
            // deactivated after being assigned, it won't be in the 'active' list, the
            // <option> disappears, the browser defaults to "None", and saving the form
            // for ANY unrelated reason then wipes commission_plan_id to NULL with no
            // warning. Keep it visible (and selected) even once inactive.
            $currentPlanId = (int) ($vendor['commission_plan_id'] ?? 0);
            if ($currentPlanId > 0 && ! in_array($currentPlanId, array_column($commissionPlans, 'id'), true)) {
                $current = service('commissionRepository')->findById($currentPlanId);
                if ($current !== null) {
                    $commissionPlans[] = $current;
                }
            }
        }

        return view('admin/vendors/form', [
            'title'           => ($vendor ? 'Edit' : 'New') . ' Vendor · Admin',
            'pageTitle'       => $vendor ? 'Edit Vendor' : 'New Vendor',
            'active'          => 'vendors',
            'userName'        => session()->get('user_name') ?: 'User',
            'vendor'          => $vendor,
            'logoUuid'        => $logoUuid,
            'businessTypes'   => service('businessTypeRepository')->list('active'),
            'commissionPlans' => $commissionPlans,
            'mapsKey'         => $mapsKey,
            'states'          => \App\Libraries\Geo\IndiaStates::list(),
            'stateNameToCode' => \App\Libraries\Geo\IndiaStates::nameToCodeMap(),
            'maxRadius'       => service('settingsRepository')->deliveryMaxRadiusKm(),
        ]);
    }

    /** @return array<string,mixed> */
    private function vendorInput(): array
    {
        $p = static fn (string $k): string => trim((string) service('request')->getPost($k));

        return [
            'business_type_id' => (int) service('request')->getPost('business_type_id'),
            'legal_name' => $p('legal_name'), 'display_name' => $p('display_name'),
            'email' => $p('email'), 'mobile' => $p('mobile'), 'password' => (string) service('request')->getPost('password'),
            'gstin' => ($g = strtoupper($p('gstin'))) !== '' ? $g : null, // null (not '') avoids the unique-index collision
            'gstin_raw' => $g,
            'shop_name' => $p('shop_name') ?: $p('display_name'),
            'address' => $p('address'), 'area' => $p('area'), 'city' => $p('city'),
            'state_code' => $p('state_code') ?: '27', 'pincode' => $p('pincode'),
            'latitude' => $p('latitude') ?: '0', 'longitude' => $p('longitude') ?: '0',
            'delivery_enabled' => $p('delivery_enabled'),
            'delivery_radius'  => $p('delivery_enabled') !== '' ? $p('delivery_radius') : '',
        ];
    }

    /** @param array<string,mixed> $in @return list<string> */
    private function validateVendor(array $in, bool $isCreate): array
    {
        $e = [];
        if ($in['business_type_id'] <= 0) { $e[] = 'Business type is required.'; }
        if ($in['legal_name'] === '' || $in['display_name'] === '') { $e[] = 'Legal and display name are required.'; }
        if (! filter_var($in['email'], FILTER_VALIDATE_EMAIL)) { $e[] = 'Valid email is required.'; }
        if (! preg_match('/^\+?[0-9]{10,15}$/', $in['mobile'])) { $e[] = 'Valid mobile (10-15 digits) is required.'; }
        if ($isCreate && strlen($in['password']) < 8) { $e[] = 'Password must be at least 8 characters.'; }
        if (($in['gstin_raw'] ?? '') !== '' && ! preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', (string) $in['gstin_raw'])) { $e[] = 'GSTIN must be 15 characters.'; }
        if ($in['address'] === '' || $in['city'] === '' || $in['pincode'] === '') { $e[] = 'Shop address, city and pincode are required.'; }
        if (! preg_match('/^[0-9]{6}$/', $in['pincode'])) { $e[] = 'Pincode must be 6 digits.'; }
        if (($in['delivery_enabled'] ?? '') !== '') {
            $maxR = service('settingsRepository')->deliveryMaxRadiusKm();
            if ((float) ($in['delivery_radius'] ?? 0) <= 0 || (float) $in['delivery_radius'] > $maxR) { $e[] = 'Delivery radius must be between 0.5 and ' . rtrim(rtrim(number_format($maxR, 2), '0'), '.') . ' km.'; }
        }

        return $e;
    }

    public function approve(int $id): RedirectResponse
    {
        return $this->transition($id, 'vendor.approve', 'approved', 'Vendor approved.');
    }

    public function reject(int $id): RedirectResponse
    {
        return $this->transition($id, 'vendor.reject', 'rejected', 'Vendor rejected.');
    }

    /**
     * Vendor status lifecycle, phase 2 (schema fix in
     * database/sql/84_vendor_status_lifecycle.sql was phase 1). Writes ONLY
     * vendors.status — nothing yet reads it for storefront/API visibility or login
     * gating, so this pair is safe standalone. The gate, once it lands, will read this
     * exact value: 'active' means operational, anything else does not.
     */
    public function activate(int $id): RedirectResponse
    {
        if ($denied = $this->guard('vendor.activate')) {
            return $denied;
        }
        if ($blocked = $this->refuseIfTerminated($id)) {
            return $blocked;
        }

        return $this->transition($id, 'vendor.activate', 'active', 'Vendor activated.');
    }

    /**
     * Writes 'suspended', deliberately NOT 'terminated'. 'terminated' already exists,
     * already blocks admin impersonation (PortalController::enterVendor/enterShop), and
     * reinstating from it is meant to stay a separate, more deliberate action — not a
     * side effect of this simple, reversible toggle.
     */
    public function deactivate(int $id): RedirectResponse
    {
        if ($denied = $this->guard('vendor.deactivate')) {
            return $denied;
        }
        if ($blocked = $this->refuseIfTerminated($id)) {
            return $blocked;
        }

        return $this->transition($id, 'vendor.deactivate', 'suspended', 'Vendor deactivated.');
    }

    /**
     * 'terminated' is out of reach of activate/deactivate in both directions. Checked
     * server-side, not left to a hidden button — a hidden button is not a guard.
     */
    private function refuseIfTerminated(int $id): ?RedirectResponse
    {
        $vendor = service('vendorRepository')->findById($id);
        if (($vendor['status'] ?? '') === 'terminated') {
            return redirect()->to('admin/vendors')->with('error', 'This vendor is terminated. Activate/Deactivate do not apply — terminated is a separate, final state.');
        }

        return null;
    }

    /** Admin view of a vendor's uploaded KYC documents (previously vendor-only). */
    public function documents(int $id)
    {
        if ($denied = $this->guard('vendor.view')) {
            return $denied;
        }
        $vendor = service('vendorRepository')->find($id);
        if ($vendor === null) {
            return redirect()->to('admin/vendors')->with('error', 'Vendor not found.');
        }
        $docs = \Config\Database::connect()->table('vendor_documents vd')
            ->select('vd.id, vd.doc_type, vd.status, vd.expires_at, vd.created_at, ma.uuid, ma.mime')
            ->join('media_assets ma', 'ma.id = vd.media_id', 'left')
            ->where('vd.vendor_id', $id)->where('vd.deleted_at', null)
            ->orderBy('vd.id', 'DESC')->get()->getResultArray();

        return view('admin/vendors/documents', [
            'title'     => 'Documents · ' . ($vendor['display_name'] ?? 'Vendor'),
            'pageTitle' => 'KYC Documents',
            'active'    => 'vendors',
            'userName'  => session()->get('user_name') ?: 'User',
            'vendor'    => $vendor,
            'docs'      => $docs,
        ]);
    }

    /** Vendor financial statement for a period (settlements + totals; CSV export). */
    public function statement(int $id)
    {
        if ($denied = $this->guard('vendor.view')) {
            return $denied;
        }
        $vendor = service('vendorRepository')->find($id);
        if ($vendor === null) {
            return redirect()->to('admin/vendors')->with('error', 'Vendor not found.');
        }
        $from = (string) ($this->request->getGet('from') ?: date('Y-m-d', strtotime('-3 months')));
        $to   = (string) ($this->request->getGet('to') ?: date('Y-m-d'));
        $rows = service('settlementRepository')->forVendorPeriod($id, $from, $to);

        $cols   = ['gross', 'commission_total', 'refund_total', 'tcs', 'tds', 'fees', 'adjustments', 'net_payable'];
        $totals = array_fill_keys($cols, 0.0);
        foreach ($rows as $r) {
            foreach ($cols as $c) {
                $totals[$c] += (float) ($r[$c] ?? 0);
            }
        }

        if ($this->request->getGet('export') === 'csv') {
            $out = fopen('php://temp', 'r+');
            fputcsv($out, ['period_start', 'period_end', ...$cols, 'status']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['period_start'], $r['period_end'], ...array_map(static fn ($c) => $r[$c], $cols), $r['status']]);
            }
            fputcsv($out, ['TOTAL', '', ...array_map(static fn ($c) => $totals[$c], $cols), '']);
            rewind($out);
            $csv = stream_get_contents($out);
            fclose($out);

            return $this->response->setHeader('Content-Type', 'text/csv')
                ->setHeader('Content-Disposition', 'attachment; filename="statement-' . $id . '-' . $from . '-to-' . $to . '.csv"')
                ->setBody($csv);
        }

        return view('admin/vendors/statement', [
            'title'     => 'Statement · ' . ($vendor['display_name'] ?? 'Vendor'),
            'pageTitle' => 'Vendor Statement',
            'active'    => 'vendors',
            'userName'  => session()->get('user_name') ?: 'User',
            'vendor'    => $vendor,
            'rows'      => $rows,
            'totals'    => $totals,
            'from'      => $from,
            'to'        => $to,
        ]);
    }

    public function verifyDoc(int $id, int $docId): RedirectResponse
    {
        return $this->docDecision($id, $docId, 'verified', 'vendor.approve', 'Document verified.');
    }

    public function rejectDoc(int $id, int $docId): RedirectResponse
    {
        return $this->docDecision($id, $docId, 'rejected', 'vendor.reject', 'Document rejected.');
    }

    private function docDecision(int $id, int $docId, string $status, string $permission, string $okMessage): RedirectResponse
    {
        if ($denied = $this->guard($permission)) {
            return $denied;
        }
        $db = \Config\Database::connect();
        $db->table('vendor_documents')->where('id', $docId)->where('vendor_id', $id)->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => session()->get('user_id')]);
        $changed = $db->affectedRows() > 0;

        return redirect()->to('admin/vendors/' . $id . '/documents')->with($changed ? 'success' : 'error', $changed ? $okMessage : 'Document not found.');
    }

    private function transition(int $id, string $permission, string $status, string $okMessage): RedirectResponse
    {
        if ($denied = $this->guard($permission)) {
            return $denied;
        }

        $repo   = service('vendorRepository');
        $vendor = $repo->findById($id);
        if ($vendor === null) {
            return redirect()->to('admin/vendors')->with('error', 'Vendor not found.');
        }
        // The important one: without it, vendor.approve would also approve manufacturers.
        if ($wrong = $this->rejectManufacturer($vendor, $id)) {
            return $wrong;
        }

        $repo->updateStatus($id, $status, session()->get('user_id'));

        // Notify the vendor owner of the decision (fail-safe; queued for delivery).
        if (! empty($vendor['owner_user_id'])) {
            service('notificationService')->notify((int) $vendor['owner_user_id'], 'approval_update', ['kind' => 'Vendor account', 'decision' => $status]);
        }

        return redirect()->to('admin/vendors')->with('success', $okMessage);
    }

    /**
     * Refuse to act on a manufacturer through the vendor screens.
     *
     * Manufacturers share the `vendors` table under party_type='manufacturer' but have
     * their own admin screens and their own permissions. Without this, an admin holding
     * only vendor.approve could approve a manufacturer via the vendor route and bypass
     * manufacturer.approve entirely — the same "one grant silently covers two things"
     * failure the manufacturer scope classes were created to prevent.
     *
     * @param array<string,mixed> $row
     */
    private function rejectManufacturer(array $row, int $id): ?RedirectResponse
    {
        if (($row['party_type'] ?? 'vendor') === 'manufacturer') {
            return redirect()->to('admin/manufacturers/' . $id)
                ->with('error', 'That is a manufacturer — manage it from the Manufacturers screen.');
        }

        return null;
    }

    /** RBAC guard for web pages: returns a redirect if the actor lacks $permission, else null. */
    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
