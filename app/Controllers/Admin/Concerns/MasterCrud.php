<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Concerns;

use CodeIgniter\HTTP\RedirectResponse;
use Config\Database;

/**
 * MasterCrud — generic create/edit for admin master tables driven by a per-
 * controller spec(), so each simple master (brands, units, tax classes, …) gets
 * full CRUD without duplicated boilerplate. The host controller provides
 * masterSpec() and a guard(); this trait renders the generic form and persists.
 *
 * spec: ['table','slug','label','permCreate','permUpdate','fields'=>[[key,label,type,required,options?]],
 *        'hasUuid'=>bool,'slugFrom'=>?key,'extra'=>array]
 *
 * @see docs/architecture/24-ADMIN-PANEL.md
 */
trait MasterCrud
{
    /** @return array<string,mixed> */
    abstract protected function masterSpec(): array;

    /** Generic list page for masters that have no bespoke index. */
    protected function masterIndex()
    {
        $s = $this->masterSpec();
        if ($denied = $this->guard($s['permView'] ?? $s['permUpdate'])) {
            return $denied;
        }
        $rows = Database::connect()->table($s['table'])->where('deleted_at', null)->orderBy('id', 'DESC')->limit(500)->get()->getResultArray();

        return view('admin/master/index', [
            'title' => $s['label'] . 's · Admin', 'pageTitle' => $s['label'] . 's',
            'active' => $s['slug'], 'userName' => session()->get('user_name') ?: 'User',
            'spec' => $s, 'rows' => $rows,
        ]);
    }

    public function toggle(int $id): RedirectResponse
    {
        $s = $this->masterSpec();
        if ($denied = $this->guard($s['permUpdate'])) {
            return $denied;
        }
        $db  = Database::connect();
        $cur = $db->table($s['table'])->select('status')->where('id', $id)->get()->getRowArray();
        if ($cur === null) {
            return redirect()->to('admin/' . $s['slug'])->with('error', $s['label'] . ' not found.');
        }
        $next = ($cur['status'] ?? 'active') === 'active' ? 'inactive' : 'active';
        $db->table($s['table'])->where('id', $id)->update(['status' => $next, 'updated_by' => (int) session()->get('user_id')]);

        return redirect()->to('admin/' . $s['slug'])->with('success', $s['label'] . ' ' . $next . '.');
    }

    public function new()
    {
        $s = $this->masterSpec();
        if ($denied = $this->guard($s['permCreate'])) {
            return $denied;
        }

        return $this->masterForm(null);
    }

    public function edit(int $id)
    {
        $s = $this->masterSpec();
        if ($denied = $this->guard($s['permUpdate'])) {
            return $denied;
        }
        $row = Database::connect()->table($s['table'])->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
        if ($row === null) {
            return redirect()->to('admin/' . $s['slug'])->with('error', $s['label'] . ' not found.');
        }

        return $this->masterForm($row);
    }

    public function store(): RedirectResponse
    {
        $s = $this->masterSpec();
        if ($denied = $this->guard($s['permCreate'])) {
            return $denied;
        }
        [$data, $err] = $this->masterCollect($s);
        if ($err !== '') {
            return redirect()->back()->withInput()->with('error', $err);
        }
        if (! empty($s['hasUuid'])) {
            $data['uuid'] = bin2hex(random_bytes(18));
        }
        if (! empty($s['slugFrom']) && ! empty($data[$s['slugFrom']])) {
            $data['slug'] = $this->masterSlug((string) $data[$s['slugFrom']]);
        }
        $data = array_merge($data, $s['extra'] ?? []);
        $data['created_by'] = (int) session()->get('user_id');

        Database::connect()->table($s['table'])->insert($data);

        return redirect()->to('admin/' . $s['slug'])->with('success', $s['label'] . ' created.');
    }

    public function update(int $id): RedirectResponse
    {
        $s = $this->masterSpec();
        if ($denied = $this->guard($s['permUpdate'])) {
            return $denied;
        }
        $db = Database::connect();
        if ($db->table($s['table'])->where('id', $id)->where('deleted_at', null)->countAllResults() === 0) {
            return redirect()->to('admin/' . $s['slug'])->with('error', $s['label'] . ' not found.');
        }
        [$data, $err] = $this->masterCollect($s);
        if ($err !== '') {
            return redirect()->back()->withInput()->with('error', $err);
        }
        $data['updated_by'] = (int) session()->get('user_id');
        $db->table($s['table'])->where('id', $id)->update($data);

        return redirect()->to('admin/' . $s['slug'])->with('success', $s['label'] . ' updated.');
    }

    /** @param array<string,mixed>|null $row */
    protected function masterForm(?array $row)
    {
        $s = $this->masterSpec();

        return view('admin/master/form', [
            'title' => ($row ? 'Edit' : 'New') . ' ' . $s['label'] . ' · Admin',
            'pageTitle' => ($row ? 'Edit ' : 'New ') . $s['label'],
            'active' => $s['slug'], 'userName' => session()->get('user_name') ?: 'User',
            'spec' => $s, 'row' => $row,
        ]);
    }

    /** @return array{0:array<string,mixed>,1:string} [data, errorMessage] */
    private function masterCollect(array $s): array
    {
        $data = [];
        foreach ($s['fields'] as $f) {
            [$key, $label, $type, $required] = [$f[0], $f[1], $f[2], $f[3] ?? false];
            $raw = service('request')->getPost($key);
            $val = is_string($raw) ? trim($raw) : $raw;
            if ($required && ($val === null || $val === '')) {
                return [[], $label . ' is required.'];
            }
            $data[$key] = match ($type) {
                'checkbox' => $val ? 1 : 0,
                'number'   => $val === '' || $val === null ? null : $val,
                default    => $val === '' ? null : $val,
            };
        }

        return [$data, ''];
    }

    private function masterSlug(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

        return ($slug === '' ? 'item' : $slug) . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
    }
}
