<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\AwsSettingsController — edits the aws_settings key/value table (the S3
 * connection + path prefixes for the test_s3 storage server). The vendor document
 * upload reads this config. Guarded by `integration.manage`; POST is CSRF-safe.
 */
final class AwsSettingsController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        return view('admin/integrations/aws', [
            'title'     => 'AWS / S3 · Admin',
            'pageTitle' => 'AWS / S3 Configuration',
            'active'    => 'int-aws',
            'userName'  => session()->get('user_name') ?: 'User',
            'rows'      => service('awsSettingsRepository')->rows(),
        ]);
    }

    public function save(): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $repo   = service('awsSettingsRepository');
        $names  = (array) $this->request->getPost('name');
        $values = (array) $this->request->getPost('value');
        $userId = (int) session()->get('user_id');

        foreach ($names as $i => $name) {
            $repo->set((string) $name, (string) ($values[$i] ?? ''), $userId);
        }

        return redirect()->to('admin/integrations/aws')->with('success', 'AWS / S3 settings saved.');
    }

    /** Round-trip write/read/delete test against the saved S3 config. */
    public function test(): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $res = service('documentStorage')->selfTest();

        return redirect()->to('admin/integrations/aws')->with($res['ok'] ? 'success' : 'error', $res['message']);
    }

    private function guard(): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), 'integration.manage')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
