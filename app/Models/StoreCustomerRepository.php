<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;
use Throwable;

/**
 * StoreCustomerRepository — storefront customer identity + account data: login
 * lookup, guest-checkout customer creation, saved addresses, order history,
 * notifications, review submission and return requests.
 */
final class StoreCustomerRepository
{
    /** @return array<string,mixed>|null ['user_id','customer_id','name','email']. */
    public function findByEmail(string $email): ?array
    {
        $row = Database::connect()->table('customers c')
            ->select('c.id AS customer_id, u.id AS user_id, u.name, u.email')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->where('u.email', $email)->where('c.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /**
     * Normalise a raw phone string to E.164 for India (+91XXXXXXXXXX). Accepts
     * 10-digit national, 0-prefixed, 91-prefixed and +91 forms. Null if not 10
     * national digits. @see findByPhone (matching tolerates legacy shapes).
     */
    public static function normalizePhone(string $raw): ?string
    {
        $d = preg_replace('/\D/', '', $raw) ?? '';
        if (strlen($d) > 10 && str_starts_with($d, '91')) {
            $d = substr($d, -10);
        } elseif (strlen($d) === 11 && str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }

        return strlen($d) === 10 ? '+91' . $d : null;
    }

    /** @return array<string,mixed>|null ['customer_id','user_id','name','email','phone']. */
    public function findByPhone(string $e164): ?array
    {
        $nat = substr($e164, -10);
        $row = Database::connect()->table('customers c')
            ->select('c.id AS customer_id, u.id AS user_id, u.name, u.email, u.phone')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->groupStart()->where('u.phone', $e164)->orWhere('u.phone', $nat)->orWhere('u.phone', '91' . $nat)->groupEnd()
            ->where('c.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /**
     * Sign-in/sign-up by verified phone: returns the existing customer (marking
     * the phone verified) or creates a new users+customers pair. Returns the
     * lightweight identity used for the session, or null on failure.
     *
     * @return array{customer_id:int,user_id:int,name:string}|null
     */
    public function findOrCreateByPhone(string $e164, ?string $name): ?array
    {
        $now      = date('Y-m-d H:i:s');
        $existing = $this->findByPhone($e164);
        if ($existing !== null) {
            Database::connect()->table('users')->where('id', $existing['user_id'])->update(['phone_verified_at' => $now]);
            // backfill a name if the account never had one and the user gave it now
            if (trim((string) ($existing['name'] ?? '')) === '' && trim((string) $name) !== '') {
                Database::connect()->table('users')->where('id', $existing['user_id'])->update(['name' => mb_substr((string) $name, 0, 191)]);
                $existing['name'] = $name;
            }

            return ['customer_id' => (int) $existing['customer_id'], 'user_id' => (int) $existing['user_id'], 'name' => (string) ($existing['name'] ?: 'Customer')];
        }

        $db = Database::connect();
        $db->transBegin();
        try {
            $clean = mb_substr(trim((string) $name) !== '' ? (string) $name : 'Customer', 0, 191);
            $db->table('users')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'principal_type' => 'customer',
                'name' => $clean, 'phone' => $e164, 'phone_verified_at' => $now, 'status' => 'active',
            ]);
            $userId = (int) $db->insertID();
            $db->table('customers')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'user_id' => $userId, 'lifetime_value' => 0, 'status' => 'active',
            ]);
            $cid = (int) $db->insertID();
            $db->transComplete();

            return $db->transStatus() ? ['customer_id' => $cid, 'user_id' => $userId, 'name' => $clean] : null;
        } catch (Throwable) {
            $db->transRollback();

            return null;
        }
    }

    /** Reuse an existing customer by email, else create one (guest checkout). */
    public function findOrCreateGuest(string $email, string $name, string $phone): ?int
    {
        $existing = $this->findByEmail($email);
        if ($existing !== null) {
            return (int) $existing['customer_id'];
        }

        $db = Database::connect();
        $db->transBegin();
        try {
            $db->table('users')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'principal_type' => 'customer',
                'name' => mb_substr($name, 0, 191), 'email' => $email, 'phone' => $phone, 'status' => 'active',
            ]);
            $userId = (int) $db->insertID();
            $db->table('customers')->insert([
                'uuid' => bin2hex(random_bytes(18)), 'user_id' => $userId, 'lifetime_value' => 0, 'status' => 'active',
            ]);
            $cid = (int) $db->insertID();
            $db->transComplete();

            return $db->transStatus() ? $cid : null;
        } catch (Throwable) {
            $db->transRollback();

            return null;
        }
    }

    public function customerIdForUser(int $userId): ?int
    {
        $row = Database::connect()->table('customers')
            ->select('id')->where('user_id', $userId)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ? (int) $row['id'] : null;
    }

    /** @return array<string,mixed>|null */
    public function profile(int $customerId): ?array
    {
        $row = Database::connect()->table('customers c')
            ->select('c.id, c.lifetime_value, c.status, u.name, u.email, u.phone, c.created_at')
            ->join('users u', 'u.id = c.user_id', 'left')
            ->where('c.id', $customerId)->where('c.deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /**
     * Update the customer's contact details (stored on the linked users row).
     * Returns false on failure (e.g. the email is already used by another user).
     */
    public function updateProfile(int $customerId, string $name, string $email, string $phone): bool
    {
        $db = Database::connect();
        $c  = $db->table('customers')->select('user_id')->where('id', $customerId)->where('deleted_at', null)->get()->getRowArray();
        if ($c === null) {
            return false;
        }
        // Guard email uniqueness ourselves so a dup doesn't throw a DB exception.
        if ($email !== '') {
            $dup = $db->table('users')->select('id')->where('email', $email)->where('id !=', (int) $c['user_id'])->where('deleted_at', null)->get()->getRowArray();
            if ($dup !== null) {
                return false;
            }
        }

        try {
            return (bool) $db->table('users')->where('id', (int) $c['user_id'])->update([
                'name'  => mb_substr($name, 0, 191),
                'email' => $email !== '' ? mb_substr($email, 0, 191) : null,
                'phone' => $phone !== '' ? mb_substr($phone, 0, 20) : null,
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<array<string,mixed>> */
    public function addresses(int $customerId): array
    {
        return Database::connect()->table('customer_addresses')
            ->select('id, label, recipient_name, phone, line1, line2, city, state_code, pincode, formatted_address, latitude, longitude, is_default')
            ->where('customer_id', $customerId)->where('deleted_at', null)
            ->orderBy('is_default', 'DESC')->orderBy('id', 'DESC')
            ->get()->getResultArray();
    }

    /** A single saved address owned by the customer, or null. @return array<string,mixed>|null */
    public function address(int $id, int $customerId): ?array
    {
        $row = Database::connect()->table('customer_addresses')
            ->select('id, label, recipient_name, phone, line1, line2, city, state_code, pincode, formatted_address, latitude, longitude, is_default')
            ->where('id', $id)->where('customer_id', $customerId)->where('deleted_at', null)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** @param array<string,mixed> $d @return int|null new address id, or null on failure */
    public function addAddress(int $customerId, array $d): ?int
    {
        $db = Database::connect();
        // A customer has at most one default — setting this one clears the rest.
        if (! empty($d['is_default'])) {
            $db->table('customer_addresses')->where('customer_id', $customerId)->update(['is_default' => 0]);
        }
        $ok = $db->table('customer_addresses')->insert($this->addressRow($customerId, $d) + ['uuid' => bin2hex(random_bytes(18)), 'customer_id' => $customerId]);

        return $ok ? (int) $db->insertID() : null;
    }

    /** Update a saved address owned by the customer. @param array<string,mixed> $d */
    public function updateAddress(int $id, int $customerId, array $d): bool
    {
        $db = Database::connect();
        if (! empty($d['is_default'])) {
            $db->table('customer_addresses')->where('customer_id', $customerId)->where('id !=', $id)->update(['is_default' => 0]);
        }

        return (bool) $db->table('customer_addresses')
            ->where('id', $id)->where('customer_id', $customerId)->where('deleted_at', null)
            ->update($this->addressRow($customerId, $d));
    }

    /** Shared column map for add/update. @param array<string,mixed> $d @return array<string,mixed> */
    private function addressRow(int $customerId, array $d): array
    {
        return [
            'label'             => mb_substr((string) ($d['label'] ?? 'Home'), 0, 40),
            'recipient_name'    => ($d['recipient_name'] ?? '') !== '' ? mb_substr((string) $d['recipient_name'], 0, 191) : null,
            'phone'             => ($d['phone'] ?? '') !== '' ? mb_substr((string) $d['phone'], 0, 20) : null,
            'line1'             => mb_substr((string) ($d['line1'] ?? ''), 0, 191),
            'line2'             => mb_substr((string) ($d['line2'] ?? ''), 0, 191),
            'city'              => mb_substr((string) ($d['city'] ?? ''), 0, 80),
            'state_code'        => mb_substr((string) ($d['state_code'] ?? ''), 0, 2),
            'pincode'           => mb_substr((string) ($d['pincode'] ?? ''), 0, 10),
            'formatted_address' => ($d['formatted_address'] ?? '') !== '' ? mb_substr((string) $d['formatted_address'], 0, 500) : null,
            'latitude'          => ($d['latitude'] ?? '') !== '' ? $d['latitude'] : null,
            'longitude'         => ($d['longitude'] ?? '') !== '' ? $d['longitude'] : null,
            'is_default'        => ! empty($d['is_default']) ? 1 : 0,
        ];
    }

    public function deleteAddress(int $id, int $customerId): bool
    {
        return Database::connect()->table('customer_addresses')
            ->where('id', $id)->where('customer_id', $customerId)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);
    }

    /** @return list<array<string,mixed>> */
    public function notifications(int $userId): array
    {
        return Database::connect()->table('notifications')
            ->select('event_code, title, body, category, status, created_at')
            ->where('user_id', $userId)->where('deleted_at', null)
            ->orderBy('created_at', 'DESC')->limit(50)
            ->get()->getResultArray();
    }

    public function submitReview(int $customerId, int $productId, int $rating, string $title, string $body): bool
    {
        return (bool) Database::connect()->table('reviews')->insert([
            'uuid'        => bin2hex(random_bytes(18)),
            'product_id'  => $productId,
            'customer_id' => $customerId,
            'rating'      => max(1, min(5, $rating)),
            'title'       => mb_substr($title, 0, 191),
            'body'        => $body,
            'status'      => 'pending',
        ]);
    }

    /** Return/refund request -> a support ticket (category 'return'). */
    public function createReturnRequest(int $customerId, int $userId, string $orderNo, string $reason): bool
    {
        return (bool) Database::connect()->table('support_tickets')->insert([
            'uuid'              => bin2hex(random_bytes(18)),
            'ticket_no'         => 'RET-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'requester_user_id' => $userId,
            'requester_type'    => 'customer',
            'subject'           => 'Return / refund request — ' . $orderNo,
            'body'              => $reason,
            'category'          => 'return',
            'priority'          => 'high',
            'status'            => 'open',
        ]);
    }
}
