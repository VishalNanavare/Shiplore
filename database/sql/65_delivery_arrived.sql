-- Add the 'arrived' delivery stage (rider reached the drop point) between
-- picked_up and out_for_delivery, per the delivery flow spec.
-- FK checks are toggled because MODIFY COLUMN rebuilds the table and would
-- otherwise re-validate fk_deliveries_suborder (an enum widening touches no FK data).
SET FOREIGN_KEY_CHECKS = 0;
ALTER TABLE deliveries
  MODIFY COLUMN status ENUM(
    'pending','assigned','picked_up','arrived','out_for_delivery','delivered','failed','returned'
  ) NOT NULL DEFAULT 'pending';
SET FOREIGN_KEY_CHECKS = 1;
