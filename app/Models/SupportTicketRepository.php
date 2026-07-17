<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * SupportTicketRepository — admin support desk. Lists tickets (with requester +
 * vendor), shows the conversation thread, lets an agent reply (appends a
 * message) and transitions status (resolve / close / reopen). Fail-safe.
 *
 * @see docs/architecture/24-ADMIN-PANEL.md (Support / Ticket Management)
 */
final class SupportTicketRepository
{
    /** @return list<array<string,mixed>> */
    public function list(?string $status = null): array
    {
        $builder = Database::connect()->table('support_tickets t')
            ->select('t.id, t.ticket_no, t.subject, t.requester_type, t.priority, t.status, t.category, t.created_at, t.last_reply_at, u.name AS requester, v.display_name AS vendor')
            ->join('users u', 'u.id = t.requester_user_id', 'left')
            ->join('vendors v', 'v.id = t.vendor_id', 'left')
            ->where('t.deleted_at', null)
            ->orderBy('FIELD(t.status, "open", "pending", "resolved", "closed")', 'ASC', false)
            ->orderBy('t.created_at', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('t.status', $status);
        }

        return $builder->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $row = Database::connect()->table('support_tickets t')
            ->select('t.*, u.name AS requester, u.email AS requester_email, v.display_name AS vendor')
            ->join('users u', 'u.id = t.requester_user_id', 'left')
            ->join('vendors v', 'v.id = t.vendor_id', 'left')
            ->where('t.id', $id)->where('t.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function messages(int $ticketId): array
    {
        return Database::connect()->table('ticket_messages m')
            ->select('m.id, m.body, m.is_internal, m.created_at, u.name AS author')
            ->join('users u', 'u.id = m.author_user_id', 'left')
            ->where('m.ticket_id', $ticketId)
            ->orderBy('m.created_at', 'ASC')
            ->get()->getResultArray();
    }

    public function addMessage(int $ticketId, int $authorId, string $body): bool
    {
        $db = Database::connect();
        $ok = $db->table('ticket_messages')->insert([
            'ticket_id'      => $ticketId,
            'author_user_id' => $authorId,
            'body'           => $body,
        ]);
        $db->table('support_tickets')->where('id', $ticketId)
            ->update(['last_reply_at' => date('Y-m-d H:i:s'), 'updated_by' => $authorId]);

        return (bool) $ok;
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null): bool
    {
        return Database::connect()->table('support_tickets')
            ->where('id', $id)->where('deleted_at', null)
            ->update(['status' => $status, 'updated_by' => $actorId]);
    }
}
