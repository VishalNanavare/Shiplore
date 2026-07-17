<?php

declare(strict_types=1);

namespace App\Models;

use Config\Database;

/**
 * RiderPayoutPlanRepository — CRUD over rider_payout_plans (the 5 payout models:
 * fixed_commission %, per_km, per_order, salary, hybrid). A plan with vendor_id
 * NULL is a company-wide plan; otherwise it's scoped to one vendor. Plans are
 * assigned to a rider via delivery_boys.payout_plan_id.
 */
final class RiderPayoutPlanRepository
{
    public const MODELS = ['fixed_commission', 'per_km', 'per_order', 'salary', 'hybrid'];

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return Database::connect()->table('rider_payout_plans rpp')
            ->select('rpp.id, rpp.name, rpp.model, rpp.pct_of_order, rpp.per_km_rate, rpp.per_order_amount, rpp.base_salary, rpp.incentive_threshold, rpp.status, rpp.vendor_id, v.display_name AS vendor')
            ->join('vendors v', 'v.id = rpp.vendor_id', 'left')
            ->orderBy('rpp.id', 'DESC')->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = Database::connect()->table('rider_payout_plans')->where('id', $id)->get()->getRowArray();

        return $row ?: null;
    }

    /** Active plans for the assign dropdown. @return list<array{id:int,name:string,model:string}> */
    public function options(): array
    {
        return Database::connect()->table('rider_payout_plans')
            ->select('id, name, model')->where('status', 'active')->orderBy('name')->get()->getResultArray();
    }

    /** @param array<string,mixed> $d */
    public function create(array $d, ?int $actorId = null): ?int
    {
        $db = Database::connect();
        $db->table('rider_payout_plans')->insert($this->columns($d) + ['created_by' => $actorId]);
        $id = (int) $db->insertID();

        return $id ?: null;
    }

    /** @param array<string,mixed> $d */
    public function update(int $id, array $d, ?int $actorId = null): bool
    {
        return (bool) Database::connect()->table('rider_payout_plans')->where('id', $id)
            ->update($this->columns($d));
    }

    public function toggle(int $id): bool
    {
        $row = $this->find($id);
        if ($row === null) {
            return false;
        }
        $to = ($row['status'] ?? 'active') === 'active' ? 'inactive' : 'active';

        return (bool) Database::connect()->table('rider_payout_plans')->where('id', $id)->update(['status' => $to]);
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function columns(array $d): array
    {
        $model = in_array($d['model'] ?? '', self::MODELS, true) ? $d['model'] : 'per_order';
        $vid   = (int) ($d['vendor_id'] ?? 0);

        return [
            'name'                => mb_substr(trim((string) ($d['name'] ?? '')), 0, 80),
            'model'               => $model,
            'pct_of_order'        => (float) ($d['pct_of_order'] ?? 0),
            'per_km_rate'         => (float) ($d['per_km_rate'] ?? 0),
            'per_order_amount'    => (float) ($d['per_order_amount'] ?? 0),
            'base_salary'         => (float) ($d['base_salary'] ?? 0),
            'incentive_threshold' => (int) ($d['incentive_threshold'] ?? 0),
            'vendor_id'           => $vid > 0 ? $vid : null,
            'status'              => ($d['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
        ];
    }
}
