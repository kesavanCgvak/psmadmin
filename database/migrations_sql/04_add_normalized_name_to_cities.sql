-- Migration: Add normalized_name column to cities table
-- Date: 2025-12-18

-- Step 1: Add normalized_name column
ALTER TABLE `cities` 
ADD COLUMN `normalized_name` VARCHAR(255) NULL AFTER `name`;

-- Step 2: Create composite index for faster duplicate detection (country_id + state_id + normalized_name)
CREATE INDEX `cities_country_state_normalized_name_index` ON `cities` (`country_id`, `state_id`, `normalized_name`);

-- Step 3: Backfill existing records
-- Note: This SQL does basic normalization (lowercase, trim, remove symbols)
-- For exact PHP normalization, use the backfill script below
UPDATE `cities` 
SET `normalized_name` = LOWER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
    `name`,
    '-', ''), '_', ''), '.', ''), ',', ''), ';', ''), ':', ''), '!', ''), '?', ''), 
    '(', ''), ')', ''), '[', ''), ']', ''), '{', ''), '}', '')));

-- Step 4: Make normalized_name NOT NULL after backfill (optional)
-- ALTER TABLE `cities` MODIFY COLUMN `normalized_name` VARCHAR(255) NOT NULL;

