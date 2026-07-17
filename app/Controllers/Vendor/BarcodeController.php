<?php

declare(strict_types=1);

namespace App\Controllers\Vendor;

use App\Libraries\Catalog\BarcodeLabelService;

/**
 * Vendor\BarcodeController — Phase S3: print barcode labels for a product's
 * variants. A pure label sheet (BarcodeLabelService) rendered to a PDF via the
 * X2c pipeline. Printing is an operational action (no approval), gated by
 * barcode.print; the owner always may.
 */
final class BarcodeController extends BaseVendorController
{
    private function ownProduct(int $productId): ?array
    {
        $p = service('adminProductRepository')->findById($productId);

        return ($p !== null && (int) $p['vendor_id'] === $this->vendorId()) ? $p : null;
    }

    private function mayPrint(): bool
    {
        return $this->isOwner() || $this->can('barcode.print');
    }

    public function index(int $productId)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->mayPrint()) {
            return redirect()->to('vendor/products')->with('error', "You don't have permission to print barcodes.");
        }
        $product = $this->ownProduct($productId);
        if ($product === null) {
            return redirect()->to('vendor/products')->with('error', 'Product not found.');
        }

        return $this->render('vendor/barcode/print', 'products', 'Print Barcodes', [
            'product'  => $product,
            'variants' => service('productVariantRepository')->listWithValues($productId),
        ]);
    }

    public function print(int $productId)
    {
        if ($denied = $this->requireVendor()) {
            return $denied;
        }
        if (! $this->mayPrint()) {
            return redirect()->to('vendor/products')->with('error', "You don't have permission to print barcodes.");
        }
        $product = $this->ownProduct($productId);
        if ($product === null) {
            return redirect()->to('vendor/products')->with('error', 'Product not found.');
        }

        $qtys   = (array) $this->request->getPost('qty'); // variant_id => copies
        $labels = [];
        foreach (service('productVariantRepository')->listWithValues($productId) as $v) {
            $copies = (int) ($qtys[(int) $v['id']] ?? 0);
            if ($copies <= 0) {
                continue;
            }
            $labels[] = [
                'title'   => (string) ($product['title'] ?? ''),
                'sku'     => (string) ($v['sku'] ?? ''),
                'mrp'     => $v['mrp'] ?? ($product['mrp'] ?? ''),
                'barcode' => (string) (($v['barcode'] ?? '') !== '' ? $v['barcode'] : ($v['sku'] ?? '')),
                'qty'     => $copies,
            ];
        }
        if ($labels === []) {
            return redirect()->to('vendor/products/' . $productId . '/barcodes')->with('error', 'Choose at least one variant and a quantity.');
        }

        $html = BarcodeLabelService::sheetHtml($labels, (int) ($this->request->getPost('columns') ?: 3));
        $pdf  = service('documentPdfService')->renderHtml($html, 'a4');

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="labels-' . $productId . '.pdf"')
            ->setBody($pdf);
    }
}
