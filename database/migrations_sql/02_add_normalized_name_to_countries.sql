-- Migration: Add normalized_name column to countries table
-- Date: 2025-12-18

-- Step 1: Add normalized_name column
ALTER TABLE `countries` 
ADD COLUMN `normalized_name` VARCHAR(255) NULL AFTER `name`;

-- Step 2: Create composite index for faster duplicate detection (region_id + normalized_name)
CREATE INDEX `countries_region_normalized_name_index` ON `countries` (`region_id`, `normalized_name`);

-- Step 3: Backfill existing records
-- Note: This SQL does basic normalization (lowercase, trim, remove symbols)
-- For exact PHP normalization, use the backfill script below
UPDATE `countries` 
SET `normalized_name` = LOWER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
    `name`,
    '-', ''), '_', ''), '.', ''), ',', ''), ';', ''), ':', ''), '!', ''), '?', ''), 
    '(', ''), ')', ''), '[', ''), ']', ''), '{', ''), '}', '')));

-- Step 4: Make normalized_name NOT NULL after backfill (optional)
-- ALTER TABLE `countries` MODIFY COLUMN `normalized_name` VARCHAR(255) NOT NULL;

