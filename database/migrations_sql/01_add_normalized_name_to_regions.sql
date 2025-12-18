-- Migration: Add normalized_name column to regions table
-- Date: 2025-12-18

-- Step 1: Add normalized_name column
ALTER TABLE `regions` 
ADD COLUMN `normalized_name` VARCHAR(255) NULL AFTER `name`;

-- Step 2: Create index for faster duplicate detection
CREATE INDEX `regions_normalized_name_index` ON `regions` (`normalized_name`);

-- Step 3: Backfill existing records
-- Note: This SQL does basic normalization (lowercase, trim, remove symbols)
-- For exact PHP normalization, use the backfill script below
UPDATE `regions` 
SET `normalized_name` = LOWER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
    `name`,
    '-', ''), '_', ''), '.', ''), ',', ''), ';', ''), ':', ''), '!', ''), '?', ''), 
    '(', ''), ')', ''), '[', ''), ']', ''), '{', ''), '}', '')));

-- Step 4: Make normalized_name NOT NULL after backfill (optional)
-- ALTER TABLE `regions` MODIFY COLUMN `normalized_name` VARCHAR(255) NOT NULL;

