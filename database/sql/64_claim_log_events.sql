-- Widen the claim-log event enum to cover every event the code actually emits.
-- 'regenerated' was already used by OTP regeneration but missing here (silently
-- dropped); 'rider_assigned'/'rider_reassigned'/'force_claimed'/'delivery_override'
-- are added for the order-ownership spec completion.
ALTER TABLE sub_order_claim_logs
  MODIFY COLUMN event ENUM(
    'claimed','refreshed','released','force_released','expired','escalated',
    'otp_attempt','regenerated','rider_assigned','rider_reassigned',
    'force_claimed','delivery_override'
  ) NOT NULL;
