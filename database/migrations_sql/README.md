# SQL Migration Scripts for Normalized Name Columns

This directory contains SQL scripts to add `normalized_name` columns to the geography tables for duplicate detection.

## Files

- `00_run_all_migrations.sql` - **Run this file** to execute all migrations at once
- `01_add_normalized_name_to_regions.sql` - Individual migration for regions table
- `02_add_normalized_name_to_countries.sql` - Individual migration for countries table
- `03_add_normalized_name_to_states_provinces.sql` - Individual migration for states_provinces table
- `04_add_normalized_name_to_cities.sql` - Individual migration for cities table

## Quick Start

### Option 1: Run All Migrations at Once (Recommended)

```bash
mysql -u your_username -p your_database_name < 00_run_all_migrations.sql
```

Or using MySQL command line:
```sql
SOURCE /path/to/00_run_all_migrations.sql;
```

### Option 2: Run Individual Migrations

Run each file in order:
```bash
mysql -u your_username -p your_database_name < 01_add_normalized_name_to_regions.sql
mysql -u your_username -p your_database_name < 02_add_normalized_name_to_countries.sql
mysql -u your_username -p your_database_name < 03_add_normalized_name_to_states_provinces.sql
mysql -u your_username -p your_database_name < 04_add_normalized_name_to_cities.sql
```

## What These Scripts Do

1. **Add `normalized_name` column** to each table (VARCHAR(255), nullable)
2. **Create indexes** for faster duplicate detection:
   - `regions`: Index on `normalized_name`
   - `countries`: Composite index on `(region_id, normalized_name)`
   - `states_provinces`: Composite index on `(country_id, normalized_name)`
   - `cities`: Composite index on `(country_id, state_id, normalized_name)`
3. **Backfill existing records** with normalized names

## Normalization Logic

The SQL normalization does:
- Convert to lowercase
- Trim whitespace
- Remove symbols: `-`, `_`, `.`, `,`, `;`, `:`, `!`, `?`, `(`, `)`, `[`, `]`, `{`, `}`

**Note:** The SQL normalization is slightly different from PHP normalization (doesn't collapse multiple spaces). For exact PHP normalization, use the Laravel migration or the PHP backfill script.

## PHP Backfill Script (For Exact Normalization)

If you need exact PHP normalization (including collapsing multiple spaces), create and run this Artisan command:

```bash
php artisan make:command BackfillNormalizedNames
```

Then run:
```bash
php artisan geography:backfill-normalized-names
```

## Verification

After running the migrations, verify with:

```sql
-- Check columns exist
SHOW COLUMNS FROM `regions` LIKE 'normalized_name';
SHOW COLUMNS FROM `countries` LIKE 'normalized_name';
SHOW COLUMNS FROM `states_provinces` LIKE 'normalized_name';
SHOW COLUMNS FROM `cities` LIKE 'normalized_name';

-- Check indexes
SHOW INDEX FROM `regions` WHERE Key_name LIKE '%normalized%';
SHOW INDEX FROM `countries` WHERE Key_name LIKE '%normalized%';
SHOW INDEX FROM `states_provinces` WHERE Key_name LIKE '%normalized%';
SHOW INDEX FROM `cities` WHERE Key_name LIKE '%normalized%';

-- Check for NULL values (should be 0 after backfill)
SELECT COUNT(*) as null_count FROM `regions` WHERE `normalized_name` IS NULL;
SELECT COUNT(*) as null_count FROM `countries` WHERE `normalized_name` IS NULL;
SELECT COUNT(*) as null_count FROM `states_provinces` WHERE `normalized_name` IS NULL;
SELECT COUNT(*) as null_count FROM `cities` WHERE `normalized_name` IS NULL;
```

## Rollback (If Needed)

To rollback these changes:

```sql
-- Drop indexes
DROP INDEX `regions_normalized_name_index` ON `regions`;
DROP INDEX `countries_region_normalized_name_index` ON `countries`;
DROP INDEX `states_provinces_country_normalized_name_index` ON `states_provinces`;
DROP INDEX `cities_country_state_normalized_name_index` ON `cities`;

-- Drop columns
ALTER TABLE `regions` DROP COLUMN `normalized_name`;
ALTER TABLE `countries` DROP COLUMN `normalized_name`;
ALTER TABLE `states_provinces` DROP COLUMN `normalized_name`;
ALTER TABLE `cities` DROP COLUMN `normalized_name`;
```

## Important Notes

⚠️ **BACKUP YOUR DATABASE** before running these migrations!

- The migrations are safe to run on production
- They add nullable columns, so existing data is not affected
- The backfill UPDATE statements will normalize all existing records
- Future records will be auto-populated by the Laravel models

## Performance

After running these migrations:
- Duplicate detection queries will use indexed columns (much faster)
- Composite indexes ensure efficient lookups within parent hierarchies
- No performance impact on existing queries

