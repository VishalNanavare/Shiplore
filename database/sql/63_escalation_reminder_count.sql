-- Escalation reminder cap: track how many reminders/urgent pings have been sent
-- at the current escalation level so EscalationService can honour MAX_REMINDERS
-- instead of notifying forever. Reset to 0 whenever the level changes or the
-- order is accepted (handled in PHP).
ALTER TABLE sub_orders
  ADD COLUMN escalation_reminder_count TINYINT UNSIGNED NOT NULL DEFAULT 0
    AFTER escalation_notified_at;
