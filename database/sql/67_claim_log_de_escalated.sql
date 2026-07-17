-- Widen sub_order_claim_logs.event to record an admin de-escalation (hand the
-- order back down to the shop). Escalation is one-way (shop -> vendor -> admin)
-- and the only legitimate downward path is an explicit admin action; this event
-- audits that hand-back. Mirrors App\Controllers\Admin\OrderController::returnToShop.
ALTER TABLE sub_order_claim_logs
    MODIFY event ENUM(
        'claimed','refreshed','released','force_released','expired','escalated',
        'otp_attempt','regenerated','rider_assigned','rider_reassigned',
        'force_claimed','delivery_override','de_escalated'
    ) NOT NULL;
