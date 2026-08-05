<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

/** Admin\ReportController — aggregate reports + GST report + CSV exports. */
final class ReportController extends BaseController
{
    /** GST collected summary (GSTR-style) for a period. */
    public function gst()
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), 'gst.report.view')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }
        [$start, $end] = $this->period();

        return view('admin/reports/gst', [
            'title' => 'GST Report · Admin', 'pageTitle' => 'GST Report', 'active' => 'reports',
            'userName' => session()->get('user_name') ?: 'User',
            'start' => $start, 'end' => $end,
            'gst' => service('reportRepository')->gstSummary($start, $end),
        ]);
    }

    /** X5 — queue an async export (large periods; downloads from media). */
    public function exportAsync(): \CodeIgniter\HTTP\RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), 'report.export')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }
        [$start, $end] = $this->period();
        service('exportJobRepository')->create('sales', ['from' => $start, 'to' => $end], (int) session()->get('user_id'));

        return redirect()->to('admin/exports')->with('success', 'Export queued — it appears below when the worker finishes (php spark sync:work).');
    }

    /** X5 — the export-job register with download links. */
    public function exports()
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), 'report.export')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return view('admin/reports/exports', [
            'title' => 'Exports',
            'jobs'  => service('exportJobRepository')->list(),
        ]);
    }

    /** Stream a CSV of sub-orders (sales + GST) for the period. */
    public function exportSales(): ResponseInterface|\CodeIgniter\HTTP\RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), 'report.export')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }
        [$start, $end] = $this->period();
        $rows = service('reportRepository')->exportRows($start, $end);

        $csv = "order_no,sub_order_no,vendor,taxable,cgst,sgst,igst,grand_total,status,created_at\r\n";
        foreach ($rows as $r) {
            $csv .= implode(',', array_map(static fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', [
                $r['order_no'], $r['sub_order_no'], $r['vendor'], $r['taxable_value'], $r['cgst'], $r['sgst'], $r['igst'], $r['grand_total'], $r['status'], $r['created_at'],
            ])) . "\r\n";
        }

        return $this->response
            ->setHeader('Content-Type', 'text/csv')
            ->setHeader('Content-Disposition', 'attachment; filename="sales-gst-' . $start . '_' . $end . '.csv"')
            ->setBody($csv);
    }

    /** @return array{0:string,1:string} */
    private function period(): array
    {
        $start = trim((string) $this->request->getGet('start'));
        $end   = trim((string) $this->request->getGet('end'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $start = date('Y-m-01');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $end = date('Y-m-d');
        }

        return [$start, $end];
    }

    public function index()
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), 'report.view')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        $repo = service('reportRepository');

        return view('admin/reports/index', [
            'title'       => 'Reports · Admin',
            'pageTitle'   => 'Reports & Analytics',
            'active'      => 'reports',
            'userName'    => session()->get('user_name') ?: 'User',
            'summary'     => $repo->summary(),
            'byChannel'   => $repo->salesByChannel(),
            'topVendors'  => $repo->topVendors(),
        ]);
    }
}
