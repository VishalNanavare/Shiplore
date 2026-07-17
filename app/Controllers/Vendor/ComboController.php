<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Vendor\ComboController — Phase S3: build combo offers (Coke + Chips). A
 * POS-only combo needs the vendor"s approval (AR-13); an online combo needs
 * vendor → admin (AR-14). The owner creates directly. Component variants are
 * the vendor's own.
 *
 * @see docs/architecture/44-BUSINESS-GOVERNANCE-AND-APPROVALS.md §9
 */
final class ComboController extends BaseVendorController
{
    private function mayCombo(): bool
    {
        return $this->isOwner() || $this->can('combo.manage');
    }

    public function index()
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->mayCombo()) {
            return redirect()->to('vendor/products')->with('error', "You don't have permission to manage combos.");
        }

        return $this->render('vendor/combos/index', 'combos', 'Combo Offers', [
            'combos'     => service('comboRepository')->list((int) $this->vendorId()),
            'categories' => service('adminProductRepository')->allowedCategories((int) $this->vendorId()),
            'masters'    => service('adminProductRepository')->formMasters(),
            'variants'   => service('productTypeRepository')->vendorVariants((int) $this->vendorId()),
        ]);
    }

    public function create(): RedirectResponse
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->mayCombo()) {
            return redirect()->to('vendor/combos')->with('error', "You don't have permission to manage combos.");
        }

        $name       = trim((string) $this->request->getPost('name'));
        $components = [];
        foreach ((array) $this->request->getPost('component_variant_id') as $i => $vid) {
            $vid = (int) $vid;
            $qty = (float) (((array) $this->request->getPost('component_qty'))[$i] ?? 1);
            if ($vid > 0 && $qty > 0) {
                $components[] = ['variant_id' => $vid, 'qty' => $qty];
            }
        }
        if ($name === '' || count($components) < 2) {
            return redirect()->to('vendor/combos')->with('error', 'A combo needs a name and at least two components.');
        }

        $channel = $this->request->getPost('channel') === 'online' ? 'online' : 'pos';
        $payload = [
            'name'           => $name,
            'category_id'    => (int) $this->request->getPost('category_id'),
            'tax_class_id'   => (int) $this->request->getPost('tax_class_id'),
            'unit_id'        => (int) $this->request->getPost('unit_id'),
            'mrp'            => (string) $this->request->getPost('mrp'),
            'price'          => (string) $this->request->getPost('price'),
            'inventory_mode' => $this->request->getPost('inventory_mode') === 'assembled' ? 'assembled' : 'virtual',
            'channel'        => $channel,
            'components'     => $components,
        ];

        // Owner creates directly; staff propose (POS-only → vendor; online → vendor→admin).
        if ($this->isOwner()) {
            $id = service('comboRepository')->create((int) $this->vendorId(), $payload, (int) session()->get('user_id'));

            return redirect()->to('vendor/combos')->with($id ? 'success' : 'error', $id ? 'Combo created.' : 'Could not create the combo.');
        }

        $action = $channel === 'online' ? 'create_online' : 'create_pos';
        [$type, $msg] = $this->requestFlash($this->submitChangeRequest('combo', $action, null, null, $payload, 'combo-' . md5($name)));

        return redirect()->to('vendor/combos')->with($type, $msg);
    }
}
