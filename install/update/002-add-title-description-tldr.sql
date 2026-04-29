-- Add title, description, and tldr columns to aiTexts table
-- These fields store AI-generated metadata for articles.
-- All columns are nullable for backward compatibility.

ALTER TABLE `aiTexts`
  ADD COLUMN IF NOT EXISTS `title` VARCHAR(2048) DEFAULT NULL AFTER `contents`,
  ADD COLUMN IF NOT EXISTS `description` VARCHAR(2048) DEFAULT NULL AFTER `title`,
  ADD COLUMN IF NOT EXISTS `tldr` TEXT DEFAULT NULL AFTER `description`;
