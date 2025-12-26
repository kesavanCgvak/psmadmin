-- ============================================================================
-- Complete SQL Migration Script: Add normalized_name columns to Geography Tables
-- ============================================================================
-- This script adds normalized_name columns to regions, countries, states_provinces, and cities
-- Run this script on your production/staging database
-- 
-- IMPORTANT: Backup your database before running this script!
-- ============================================================================

-- Disable foreign key checks temporarily (if needed)
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- 1. REGIONS TABLE
-- ============================================================================
ALTER TABLE `regions` 
ADD COLUMN `normalized_name` VARCHAR(255) NULL AFTER `name`;

CREATE INDEX `regions_normalized_name_index` ON `regions` (`normalized_name`);

UPDATE `regions` 
SET `normalized_name` = LOWER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
    `name`,
    '-', ''), '_', ''), '.', ''), ',', ''), ';', ''), ':', ''), '!', ''), '?', ''), 
    '(', ''), ')', ''), '[', ''), ']', ''), '{', ''), '}', '')));

-- ============================================================================
-- 2. COUNTRIES TABLE
-- ============================================================================
ALTER TABLE `countries` 
ADD COLUMN `normalized_name` VARCHAR(255) NULL AFTER `name`;

CREATE INDEX `countries_region_normalized_name_index` ON `countries` (`region_id`, `normalized_name`);

UPDATE `countries` 
SET `normalized_name` = LOWER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
    `name`,
    '-', ''), '_', ''), '.', ''), ',', ''), ';', ''), ':', ''), '!', ''), '?', ''), 
    '(', ''), ')', ''), '[', ''), ']', ''), '{', ''), '}', '')));

-- ============================================================================
-- 3. STATES_PROVINCES TABLE
-- ============================================================================
ALTER TABLE `states_provinces` 
ADD COLUMN `normalized_name` VARCHAR(255) NULL AFTER `name`;

CREATE INDEX `states_provinces_country_normalized_name_index` ON `states_provinces` (`country_id`, `normalized_name`);

UPDATE `states_provinces` 
SET `normalized_name` = LOWER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
    `name`,
    '-', ''), '_', ''), '.', ''), ',', ''), ';', ''), ':', ''), '!', ''), '?', ''), 
    '(', ''), ')', ''), '[', ''), ']', ''), '{', ''), '}', '')));

-- ============================================================================
-- 4. CITIES TABLE
-- ============================================================================
ALTER TABLE `cities` 
ADD COLUMN `normalized_name` VARCHAR(255) NULL AFTER `name`;

CREATE INDEX `cities_country_state_normalized_name_index` ON `cities` (`country_id`, `state_id`, `normalized_name`);

UPDATE `cities` 
SET `normalized_name` = LOWER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
    `name`,
    '-', ''), '_', ''), '.', ''), ',', ''), ';', ''), ':', ''), '!', ''), '?', ''), 
    '(', ''), ')', ''), '[', ''), ']', ''), '{', ''), '}', '')));

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- VERIFICATION QUERIES (Optional - run to verify)
-- ============================================================================
-- Check if columns were added:
-- SHOW COLUMNS FROM `regions` LIKE 'normalized_name';
-- SHOW COLUMNS FROM `countries` LIKE 'normalized_name';
-- SHOW COLUMNS FROM `states_provinces` LIKE 'normalized_name';
-- SHOW COLUMNS FROM `cities` LIKE 'normalized_name';

-- Check if indexes were created:
-- SHOW INDEX FROM `regions` WHERE Key_name LIKE '%normalized%';
-- SHOW INDEX FROM `countries` WHERE Key_name LIKE '%normalized%';
-- SHOW INDEX FROM `states_provinces` WHERE Key_name LIKE '%normalized%';
-- SHOW INDEX FROM `cities` WHERE Key_name LIKE '%normalized%';

-- Check for NULL values (should be 0 after backfill):
-- SELECT COUNT(*) as null_count FROM `regions` WHERE `normalized_name` IS NULL;
-- SELECT COUNT(*) as null_count FROM `countries` WHERE `normalized_name` IS NULL;
-- SELECT COUNT(*) as null_count FROM `states_provinces` WHERE `normalized_name` IS NULL;
-- SELECT COUNT(*) as null_count FROM `cities` WHERE `normalized_name` IS NULL;

