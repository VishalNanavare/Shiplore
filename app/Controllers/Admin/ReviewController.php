<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

/** Admin\ReviewController — review moderation. review.moderate. */
final class ReviewController extends BaseController
{
    public function index()
    {
        if ($denied = $this->guard('review.moderate')) {
            return $denied;
        }

        return view('admin/reviews/index', [
            'title'     => 'Reviews · Admin',
            'pageTitle' => 'Review Moderation',
            'active'    => 'reviews',
            'userName'  => session()->get('user_name') ?: 'User',
            'reviews'   => service('reviewRepository')->list($this->statusFilter()),
        ]);
    }

    public function publish(int $id): RedirectResponse
    {
        return $this->transition($id, 'published', 'Review published.', ['pending', 'flagged']);
    }

    public function reject(int $id): RedirectResponse
    {
        return $this->transition($id, 'rejected', 'Review rejected.', ['pending', 'flagged']);
    }

    /** A decided review (published OR rejected) goes back to the pending queue. */
    public function restore(int $id): RedirectResponse
    {
        return $this->transition($id, 'pending', 'Review restored to the queue.', ['published', 'rejected']);
    }

    /**
     * Apply a moderation decision, but only from a legal current status — so a
     * URL-tampered POST can't (e.g.) publish a rejected review behind the UI"s back.
     *
     * @param list<string> $from current statuses this transition is allowed from
     */
    private function transition(int $id, string $status, string $msg, array $from): RedirectResponse
    {
        if ($denied = $this->guard('review.moderate')) {
            return $denied;
        }
        $repo   = service('reviewRepository');
        $review = $repo->findById($id);
        if ($review === null) {
            return redirect()->to('admin/reviews')->with('error', 'Review not found.');
        }
        if (! in_array((string) ($review['status'] ?? ''), $from, true)) {
            return redirect()->to('admin/reviews')->with('error', "That action isn\'t allowed for this review.");
        }
        $repo->updateStatus($id, $status, (int) session()->get('user_id'));

        return redirect()->to('admin/reviews')->with('success', $msg);
    }

    private function statusFilter(): ?string
    {
        $s = $this->request->getGet('status');

        return is_string($s) ? $s : null;
    }

    private function guard(string $permission): ?RedirectResponse
    {
        if (! service('policyEngine')->canPlatform(service('scopeContext')->all(), $permission)) {
            return redirect()->to('admin/dashboard')->with('error', 'You do not have permission to do that.');
        }

        return null;
    }
}
