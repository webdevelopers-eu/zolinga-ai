-- Add priority column to aiEvents table
-- Priority is a float between 0 and 1 (exclusive). Higher values are processed first.
-- Default is 0.5 (neutral priority).

ALTER TABLE `aiEvents`
  ADD COLUMN `priority` float NOT NULL DEFAULT 0.5 AFTER `aiEvent`,
  ADD INDEX `idx_status_priority` (`status`, `priority` DESC, `id` DESC);