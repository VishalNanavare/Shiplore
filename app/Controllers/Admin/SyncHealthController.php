<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Sync\SyncRegistry;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\SyncHealthController — the sync health dashboard: queue depth, retries,
 * dead-letter, open conflicts, last-sync cursors per terminal/entity; plus
 * manual actions (resolve a conflict, requeue a dead-letter, trigger a sync run).
 * Guarded by `integration.manage`; POSTs CSRF-protected.
 *
 * @see docs/architecture/35-SYNC-ENGINE.md
 */
final class SyncHealthController extends BaseController
{
    public function index(): string|RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $engine = service('syncEngineRepository');

        return view('admin/sync/index', [
            'title'     => 'Sync Health · Admin',
            'pageTitle' => 'Sync Health',
            'active'    => 'sync-health',
            'userName'  => session()->get('user_name') ?: 'User',
            'stats'     => service('jobQueueRepository')->stats(),
            'health'    => $engine->health(),
            'conflicts' => $engine->openConflicts(),
            'deadLetter'=> $engine->deadLetters(),
            'entities'  => (new SyncRegistry())->entities(),
        ]);
    }

    public function resolveConflict(int $id): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $resolution = (string) $this->request->getPost('resolution') ?: 'server_wins';
        service('syncEngineRepository')->resolveConflict($id, $resolution, (int) session()->get('user_id'));

        return redirect()->to('admin/sync-health')->with('success', 'Conflict resolved (' . $resolution . ').');
    }

    public function requeue(int $id): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $ok = service('syncEngineRepository')->requeueDeadLetter($id, (int) session()->get('user_id'));

        return redirect()->to('admin/sync-health')->with($ok ? 'success' : 'error', $ok ? 'Item requeued for sync.' : 'Could not requeue.');
    }

    public function trigger(): RedirectResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $entity = trim((string) $this->request->getPost('entity')) ?: 'all';
        service('jobQueueRepository')->enqueue('sync', 'sync.replay', ['trigger' => 'manual', 'entity' => $entity], 'manual-' . $entity . '-' . date('YmdHis'));

        return redirect()->to('admin/sync-health')->with('success', 'Manual sync queued for: ' . $entity . '. Run the worker to process.');
    }

    private function guard(): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), 'integration.manage')) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
