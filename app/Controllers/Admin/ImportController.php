<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Import\ImportService;
use App\Libraries\Import\ImportTemplateService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * Admin\ImportController — bulk import jobs list + synchronous upload (CSV/XLSX)
 * + per-row results + downloadable Excel (.xlsx) templates. Guarded by
 * `import.run`. The product template mirrors the rich product model.
 *
 * @see docs/architecture/47-BULK-IMPORT.md
 */
final class ImportController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        return view('admin/imports/index', [
            'title' => 'Bulk Imports · Admin', 'pageTitle' => 'Bulk Imports', 'active' => 'imports',
            'userName' => session()->get('user_name') ?: 'User',
            'jobs' => service('importJobRepository')->list(),
            'types' => ImportService::TYPES,
        ]);
    }

    public function upload(): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $type = (string) $this->request->getPost('type');
        $file = $this->request->getFile('file');
        if ($file === null) {
            return redirect()->to('admin/imports')->with('error', 'Choose a file to upload.');
        }

        $res = service('importService')->run($file, $type, (int) session()->get('user_id'));
        if (! $res['ok']) {
            return redirect()->to('admin/imports')->with('error', $res['reason'] ?? 'Import failed.');
        }

        return redirect()->to('admin/imports/' . $res['job_id'])
            ->with('success', "Imported {$res['imported']} of {$res['total']} row(s); {$res['errors']} error(s).");
    }

    public function show(int $id)
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $repo = service('importJobRepository');
        $job  = $repo->findById($id);
        if ($job === null) {
            return redirect()->to('admin/imports')->with('error', 'Import job not found.');
        }

        return view('admin/imports/show', [
            'title' => 'Import #' . $id . ' · Admin', 'pageTitle' => 'Import #' . $id, 'active' => 'imports',
            'userName' => session()->get('user_name') ?: 'User',
            'job' => $job, 'rows' => $repo->rows($id),
        ]);
    }

    /** Download a real Excel (.xlsx) template for the chosen type, with an
     * Instructions sheet listing every column + the reference codes to use. */
    public function template(string $type): ResponseInterface
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $type  = in_array($type, ImportService::TYPES, true) ? $type : 'product';
        $bytes = service('importTemplateService')->xlsx($type, $type === 'product' ? $this->reference() : []);

        return $this->response
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $type . '-import-template.xlsx"')
            ->setBody($bytes);
    }

    /** Reference code lists shown on the Instructions sheet so the user knows
     * exactly what to type for vendor / category / tax / unit / brand.
     * @return array<string,list<string>> */
    private function reference(): array
    {
        // Best-effort: the template must still download even if the lookups fail;
        // the Instructions sheet just omits the reference-code lists.
        try {
            $db  = Database::connect();
            $col = static fn (array $rows, string $k): array => array_values(array_filter(array_map(static fn ($r) => (string) $r[$k], $rows)));

            return [
                'tax'      => $col($db->table('tax_classes')->select('code')->orderBy('code')->get()->getResultArray(), 'code'),
                'unit'     => $col($db->table('units')->select('code')->orderBy('code')->get()->getResultArray(), 'code'),
                'category' => $col($db->table('categories')->select('slug')->where('status', 'active')->where('deleted_at', null)->orderBy('slug')->get(40)->getResultArray(), 'slug'),
                // Vendors only: bulk import writes products.vendor_id, and manufacturer
                // catalogues are created through the manufacturer panel's making/selling
                // price form, which this template has no columns for.
                'vendor'   => $col($db->table('vendors')->select('slug')->where('deleted_at', null)->where('party_type', 'vendor')->orderBy('slug')->get(40)->getResultArray(), 'slug'),
                'brand'    => $col($db->table('brands')->select('slug')->where('status', 'active')->orderBy('slug')->get(40)->getResultArray(), 'slug'),
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    private function guard(): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), 'import.run')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
