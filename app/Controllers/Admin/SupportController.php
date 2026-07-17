<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Admin\SupportController — support ticket desk. View needs `support.view`;
 * replying needs `support.respond`; resolving/closing/reopening needs
 * `support.close`. POSTs CSRF-protected at the route.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md (Support / Ticket Management)
 */
final class SupportController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('support.view')) {
            return $denied;
        }

        $status = $this->request->getGet('status');

        return view('admin/support/index', [
            'title'     => 'Support · Admin',
            'pageTitle' => 'Support Tickets',
            'active'    => 'support',
            'userName'  => session()->get('user_name') ?: 'User',
            'tickets'   => service('supportTicketRepository')->list(is_string($status) ? $status : null),
        ]);
    }

    public function show(int $id)
    {
        if ($denied = $this->guard('support.view')) {
            return $denied;
        }

        $repo   = service('supportTicketRepository');
        $ticket = $repo->findById($id);
        if ($ticket === null) {
            return redirect()->to('admin/support')->with('error', 'Ticket not found.');
        }

        return view('admin/support/show', [
            'title'     => 'Ticket ' . ($ticket['ticket_no'] ?? '') . ' · Admin',
            'pageTitle' => 'Ticket ' . ($ticket['ticket_no'] ?? ''),
            'active'    => 'support',
            'userName'  => session()->get('user_name') ?: 'User',
            'ticket'    => $ticket,
            'messages'  => $repo->messages($id),
        ]);
    }

    public function reply(int $id): RedirectResponse
    {
        if ($denied = $this->guard('support.respond')) {
            return $denied;
        }

        $repo = service('supportTicketRepository');
        if ($repo->findById($id) === null) {
            return redirect()->to('admin/support')->with('error', 'Ticket not found.');
        }

        $body = trim((string) $this->request->getPost('body'));
        if ($body === '') {
            return redirect()->to('admin/support/' . $id)->with('error', 'Reply cannot be empty.');
        }

        $repo->addMessage($id, (int) session()->get('user_id'), $body);

        return redirect()->to('admin/support/' . $id)->with('success', 'Reply sent.');
    }

    public function resolve(int $id): RedirectResponse
    {
        return $this->transition($id, 'resolved', 'Ticket resolved.');
    }

    public function close(int $id): RedirectResponse
    {
        return $this->transition($id, 'closed', 'Ticket closed.');
    }

    public function reopen(int $id): RedirectResponse
    {
        return $this->transition($id, 'open', 'Ticket reopened.');
    }

    private function transition(int $id, string $status, string $okMessage): RedirectResponse
    {
        if ($denied = $this->guard('support.close')) {
            return $denied;
        }

        $repo = service('supportTicketRepository');
        if ($repo->findById($id) === null) {
            return redirect()->to('admin/support')->with('error', 'Ticket not found.');
        }

        $repo->updateStatus($id, $status, (int) session()->get('user_id'));

        return redirect()->to('admin/support/' . $id)->with('success', $okMessage);
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->can(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
