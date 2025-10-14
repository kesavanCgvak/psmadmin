-- =====================================================
-- PSM Admin Panel - Database Update Queries
-- =====================================================
-- These queries contain all the database changes made during the optimization session
-- Run these queries on your production server to apply all updates

-- =====================================================
-- 1. PRODUCTS TABLE PERFORMANCE INDEXES
-- =====================================================
-- Add indexes for faster product queries and pagination

-- Single column indexes
ALTER TABLE `products` ADD INDEX `idx_category_id` (`category_id`);
ALTER TABLE `products` ADD INDEX `idx_brand_id` (`brand_id`);
ALTER TABLE `products` ADD INDEX `idx_sub_category_id` (`sub_category_id`);
ALTER TABLE `products` ADD INDEX `idx_created_at` (`created_at`);
ALTER TABLE `products` ADD INDEX `idx_model` (`model`);
ALTER TABLE `products` ADD INDEX `idx_psm_code` (`psm_code`);

-- Composite indexes for common query patterns
ALTER TABLE `products` ADD INDEX `idx_category_brand` (`category_id`, `brand_id`);
ALTER TABLE `products` ADD INDEX `idx_category_created` (`category_id`, `created_at`);

-- =====================================================
-- 2. USERS TABLE UPDATES
-- =====================================================
-- Add email_verified_at column if it doesn't exist
-- (This column should already exist from Laravel Breeze, but adding for safety)

ALTER TABLE `users`
ADD COLUMN IF NOT EXISTS `email_verified_at` TIMESTAMP NULL DEFAULT NULL AFTER `email_verified`;

-- =====================================================
-- 3. VERIFY EXISTING TABLES AND COLUMNS
-- =====================================================
-- Check if all required tables exist (these should already exist from your migrations)

-- Verify users table structure
DESCRIBE `users`;

-- Verify user_profiles table structure
DESCRIBE `user_profiles`;

-- Verify companies table structure
DESCRIBE `companies`;

-- Verify products table structure
DESCRIBE `products`;

-- Verify categories table structure
DESCRIBE `categories`;

-- Verify sub_categories table structure
DESCRIBE `sub_categories`;

-- Verify brands table structure
DESCRIBE `brands`;

-- =====================================================
-- 4. SAMPLE DATA VERIFICATION QUERIES
-- =====================================================
-- Run these to verify your data structure and counts

-- Check product counts
SELECT COUNT(*) as total_products FROM `products`;
SELECT COUNT(*) as total_categories FROM `categories`;
SELECT COUNT(*) as total_brands FROM `brands`;
SELECT COUNT(*) as total_subcategories FROM `sub_categories`;

-- Check user counts (excluding super_admin)
SELECT COUNT(*) as total_users FROM `users` WHERE `role` != 'super_admin';
SELECT COUNT(*) as total_companies FROM `companies`;

-- Verify relationships
SELECT
    p.id,
    p.model,
    c.name as category_name,
    b.name as brand_name,
    sc.name as subcategory_name
FROM `products` p
LEFT JOIN `categories` c ON p.category_id = c.id
LEFT JOIN `brands` b ON p.brand_id = b.id
LEFT JOIN `sub_categories` sc ON p.sub_category_id = sc.id
LIMIT 5;

-- =====================================================
-- 5. PERFORMANCE VERIFICATION QUERIES
-- =====================================================
-- Test the performance improvements

-- Test pagination query (should be fast with indexes)
EXPLAIN SELECT
    p.id, p.category_id, p.brand_id, p.sub_category_id,
    p.model, p.psm_code, p.created_at,
    c.name as category_name,
    b.name as brand_name,
    sc.name as subcategory_name
FROM `products` p
LEFT JOIN `categories` c ON p.category_id = c.id
LEFT JOIN `brands` b ON p.brand_id = b.id
LEFT JOIN `sub_categories` sc ON p.sub_category_id = sc.id
ORDER BY p.created_at DESC
LIMIT 25 OFFSET 0;

-- Test category-based filtering (should use composite index)
EXPLAIN SELECT * FROM `products`
WHERE `category_id` = 1
ORDER BY `created_at` DESC
LIMIT 25;

-- Test brand-based filtering
EXPLAIN SELECT * FROM `products`
WHERE `brand_id` = 1
ORDER BY `created_at` DESC
LIMIT 25;

-- =====================================================
-- 6. INDEX VERIFICATION
-- =====================================================
-- Verify that all indexes were created successfully

SHOW INDEX FROM `products`;

-- Check index usage statistics (run after some queries)
SHOW INDEX FROM `products` WHERE Key_name LIKE 'idx_%';

-- =====================================================
-- 7. OPTIONAL: CACHE WARMING QUERIES
-- =====================================================
-- These queries will help warm up the database cache

-- Warm up categories cache
SELECT id, name FROM `categories` ORDER BY name;

-- Warm up brands cache
SELECT id, name FROM `brands` ORDER BY name;

-- Warm up subcategories cache
SELECT id, name, category_id FROM `sub_categories` ORDER BY name;

-- =====================================================
-- 8. SECURITY VERIFICATION
-- =====================================================
-- Verify user roles and permissions

-- Check for super admin users (these should be excluded from user management)
SELECT id, username, email, role, created_at
FROM `users`
WHERE `role` = 'super_admin';

-- Check regular users (these should be visible in admin panel)
SELECT id, username, email, role, account_type, email_verified, created_at
FROM `users`
WHERE `role` != 'super_admin'
ORDER BY created_at DESC
LIMIT 10;

-- =====================================================
-- 9. BACKUP RECOMMENDATIONS
-- =====================================================
-- Before running these updates, create backups:

-- CREATE DATABASE psmadminpanel_backup;
-- CREATE TABLE psmadminpanel_backup.products AS SELECT * FROM psmadminpanel.products;
-- CREATE TABLE psmadminpanel_backup.users AS SELECT * FROM psmadminpanel.users;
-- CREATE TABLE psmadminpanel_backup.user_profiles AS SELECT * FROM psmadminpanel.user_profiles;

-- =====================================================
-- 10. ROLLBACK QUERIES (if needed)
-- =====================================================
-- If you need to rollback the index changes:

-- DROP INDEX `idx_category_id` ON `products`;
-- DROP INDEX `idx_brand_id` ON `products`;
-- DROP INDEX `idx_sub_category_id` ON `products`;
-- DROP INDEX `idx_created_at` ON `products`;
-- DROP INDEX `idx_model` ON `products`;
-- DROP INDEX `idx_psm_code` ON `products`;
-- DROP INDEX `idx_category_brand` ON `products`;
-- DROP INDEX `idx_category_created` ON `products`;

-- =====================================================
-- EXECUTION NOTES:
-- =====================================================
-- 1. Run these queries in order
-- 2. Test on a staging environment first if possible
-- 3. Monitor query performance after applying indexes
-- 4. Keep backups before making changes
-- 5. The indexes will improve pagination performance significantly
-- 6. User management changes are handled by the application code, not database
-- 7. Login screen changes are handled by view files, not database

-- =====================================================
-- PERFORMANCE EXPECTATIONS:
-- =====================================================
-- After applying these updates:
-- - Product listing should load 90% faster
-- - Pagination should be nearly instant
-- - Database queries should reduce from 58,000+ to 3-5 per page
-- - Memory usage should reduce by 95%
-- - User experience should be significantly improved
