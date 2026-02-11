# Ranking System Migrations - New Server Setup

This document lists all migrations required for the **Job Rating/Ranking System** on a new server.

## Migration Order (Chronological)

Run these migrations in the exact order listed below:

### 1. **Job Ratings Tables** (Base)
**File:** `2026_02_02_100000_create_job_ratings_tables.php`
- Creates `job_ratings` table (one per rental job initially)
- Creates `job_rating_replies` table (provider replies to ratings)
- **Dependencies:** Requires `rental_jobs` and `supply_jobs` tables to exist

### 2. **Status Column Updates** (Required for completion flow)
**File:** `2026_02_02_130000_add_completed_pending_rating_to_supply_and_rental_jobs_status.php`
- Converts `supply_jobs.status` from ENUM to VARCHAR (supports `completed_pending_rating`, `rated`)
- Converts `rental_jobs.status` from ENUM to VARCHAR (supports `completed_pending_rating`, `rated`)
- **Dependencies:** Requires `supply_jobs` and `rental_jobs` tables to exist

### 3. **Job Ratings Per Supply Job** (Refactor)
**File:** `2026_02_02_140000_make_job_ratings_per_supply_job.php`
- Adds `supply_job_id` to `job_ratings` table
- Migrates existing ratings from one-per-rental-job to one-per-supply-job
- Updates foreign keys and constraints
- **Dependencies:** Requires `job_ratings` table from step 1

### 4. **Admin Blocking** (Low-rated provider blocking)
**File:** `2026_02_02_150000_add_blocked_by_admin_to_companies.php`
- Adds `blocked_by_admin_at` column to `companies` table
- Used to block low-rated providers from appearing in listings
- **Dependencies:** Requires `companies` table to exist

### 5. **Completion Reminders** (Email reminders)
**File:** `2026_02_02_160000_create_supply_job_completion_reminders_table.php`
- Creates `supply_job_completion_reminders` table
- Tracks reminder emails sent (2, 7, 14, 21, 30 days after unpack date)
- **Dependencies:** Requires `supply_jobs` table to exist

### 6. **Completion Reminders Unique Index** (Fix - only if needed)
**File:** `2026_02_02_160001_add_unique_to_supply_job_completion_reminders.php`
- **Note:** This migration is only needed if migration #5 failed due to MySQL identifier length limit
- For a **fresh server**, migration #5 already includes the unique index with short name
- **Skip this migration** on a new server unless you encounter the index name length error

### 7. **Completed At** (Renter rating reminder schedule)
**File:** `2026_02_02_170000_add_completed_at_to_supply_jobs.php`
- Adds `completed_at` timestamp to `supply_jobs` (set when provider marks job as completed)
- Used for renter rating reminder schedule (2, 7, 14, 21, 30 days after)
- **Dependencies:** Requires `supply_jobs` table to exist

### 8. **Renter Rating Reminders**
**File:** `2026_02_02_170001_create_supply_job_rating_reminders_table.php`
- Creates `supply_job_rating_reminders` table
- Tracks rating-request reminder emails sent to renters (2, 7, 14, 21, 30 days after completed_at)
- **Dependencies:** Requires `supply_jobs` table to exist

---

## Quick Command for New Server

Run all migrations in order:

```bash
php artisan migrate
```

Or run specific migrations:

```bash
# Step 1: Job ratings tables
php artisan migrate --path=database/migrations/2026_02_02_100000_create_job_ratings_tables.php

# Step 2: Status column updates
php artisan migrate --path=database/migrations/2026_02_02_130000_add_completed_pending_rating_to_supply_and_rental_jobs_status.php

# Step 3: Ratings per supply job
php artisan migrate --path=database/migrations/2026_02_02_140000_make_job_ratings_per_supply_job.php

# Step 4: Admin blocking
php artisan migrate --path=database/migrations/2026_02_02_150000_add_blocked_by_admin_to_companies.php

# Step 5: Completion reminders
php artisan migrate --path=database/migrations/2026_02_02_160000_create_supply_job_completion_reminders_table.php

# Step 7: Completed at (for renter reminders)
php artisan migrate --path=database/migrations/2026_02_02_170000_add_completed_at_to_supply_jobs.php

# Step 8: Renter rating reminders
php artisan migrate --path=database/migrations/2026_02_02_170001_create_supply_job_rating_reminders_table.php
```

---

## Tables Created/Modified

### New Tables:
- `job_ratings` - Stores ratings given by renters for supply jobs
- `job_rating_replies` - Stores provider replies to ratings
- `supply_job_completion_reminders` - Tracks completion reminder emails sent to providers
- `supply_job_rating_reminders` - Tracks rating-request reminder emails sent to renters

### Modified Tables:
- `supply_jobs` - Status column changed to VARCHAR; added `completed_at` (when provider marks completed)
- `rental_jobs` - Status column changed to VARCHAR (supports `completed_pending_rating`, `rated`)
- `companies` - Added `blocked_by_admin_at` column for admin blocking

---

## Features Enabled

After running these migrations, the following features are available:

1. ✅ **Job Rating System**
   - Renters can rate completed supply jobs (1-5 stars + comment)
   - Providers can reply to ratings
   - Admin can view all ratings in `/admin/job-ratings`

2. ✅ **Status Flow**
   - Supply jobs can transition: `accepted` → `completed_pending_rating` → `rated`
   - Rental jobs can transition: `completed` → `completed_pending_rating` → `rated`

3. ✅ **Admin Blocking**
   - Admin can block low-rated providers from appearing in provider listings
   - Blocked companies have `blocked_by_admin_at` set

4. ✅ **Completion Reminders (Provider)**
   - Automated email reminders sent to providers:
     - 2 days after unpack date
     - 7 days after unpack date
     - 14 days after unpack date
     - 21 days after unpack date
     - 30 days after unpack date (final reminder)
   - Reminders stop once job is marked as completed

5. ✅ **Renter Rating Request & Reminders**
   - When provider marks job as completed: email sent to renter requesting a rating (immediate)
   - If renter does not rate: follow-up reminders every 7 days (7, 14, 21, 30 days after completed date)
   - Reminders stop once renter submits a rating

---

## Post-Migration Setup

After migrations, ensure:

1. **Scheduler is running** (for completion reminders):
   ```bash
   # Add to crontab (runs every minute, Laravel handles daily execution)
   * * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
   ```

2. **Email configuration** is set in `.env`:
   ```
   MAIL_MAILER=smtp
   MAIL_HOST=...
   MAIL_PORT=...
   MAIL_USERNAME=...
   MAIL_PASSWORD=...
   MAIL_FROM_ADDRESS=...
   MAIL_FROM_NAME="Pro Subrental Marketplace"
   ```

3. **Test the flow**:
   - Complete a supply job → status becomes `completed_pending_rating`
   - Rate the job → status becomes `rated`
   - Check admin panel at `/admin/job-ratings`

---

## Rollback (if needed)

To rollback migrations in reverse order:

```bash
php artisan migrate:rollback --step=6  # Rollback last 6 migrations
```

Or rollback specific migration:

```bash
php artisan migrate:rollback --path=database/migrations/2026_02_02_160000_create_supply_job_completion_reminders_table.php
```

**Warning:** Migration `2026_02_02_140000` contains data migration logic. Rolling it back will lose the per-supply-job ratings structure.
