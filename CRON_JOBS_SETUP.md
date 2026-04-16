# Cron Jobs Setup - Laravel Scheduler

This document provides details about the scheduled tasks (cron jobs) configured for the Job Rating/Ranking System.

---

## Overview

Laravel uses a **single cron entry** that runs the scheduler every minute. The scheduler then determines which tasks need to run based on their schedule (daily, hourly, etc.).

---

## Required Cron Job (Single Entry)

Add this **one line** to your server's crontab:

```bash
* * * * * cd /path-to-your-app && php artisan schedule:run >> /dev/null 2>&1
```

**Replace `/path-to-your-app`** with your actual application path.

### Example Paths:

```bash
# If app is in /var/www/psmadmin
* * * * * cd /var/www/psmadmin && php artisan schedule:run >> /dev/null 2>&1

# If app is in /home/username/public_html/psmadmin
* * * * * cd /home/username/public_html/psmadmin && php artisan schedule:run >> /dev/null 2>&1

# If app is in /opt/psmadmin
* * * * * cd /opt/psmadmin && php artisan schedule:run >> /dev/null 2>&1
```

---

## How to Add Cron Job

### Method 1: Using crontab -e (Recommended)

```bash
# SSH into your server
ssh user@your-server.com

# Edit crontab
crontab -e

# Add the line (replace path)
* * * * * cd /path-to-your-app && php artisan schedule:run >> /dev/null 2>&1

# Save and exit (Ctrl+X, then Y, then Enter for nano)
```

### Method 2: Using cPanel (If Available)

1. Log into cPanel
2. Go to **Cron Jobs**
3. Add new cron job:
   - **Minute:** `*`
   - **Hour:** `*`
   - **Day:** `*`
   - **Month:** `*`
   - **Weekday:** `*`
   - **Command:** `cd /path-to-your-app && php artisan schedule:run >> /dev/null 2>&1`

### Method 3: Direct File Edit

```bash
# Edit crontab file directly
sudo nano /etc/crontab

# Or for specific user
sudo nano /var/spool/cron/crontabs/username
```

---

## Scheduled Commands

The following commands are scheduled to run **daily**:

### 1. Provider Completion Reminders

**Command:** `supply-jobs:send-completion-reminders`  
**Schedule:** Daily (runs once per day)  
**Purpose:** Send reminder emails to providers to mark jobs as completed

**When reminders are sent:**
- 2 days after unpack date
- 7 days after unpack date
- 14 days after unpack date
- 21 days after unpack date
- 30 days after unpack date (final reminder)

**Conditions:**
- Only for supply jobs with `status = 'accepted'`
- Only if `unpacking_date` is set and in the past
- Reminders stop once job is marked as completed
- Each reminder is sent only once (tracked in `supply_job_completion_reminders` table)

**Command Class:** `App\Console\Commands\SendSupplyJobCompletionReminders`

---

### 2. Renter Rating Reminders

**Command:** `supply-jobs:send-renter-rating-reminders`  
**Schedule:** Daily (runs once per day)  
**Purpose:** Send reminder emails to renters to rate completed jobs

**When reminders are sent:**
- 7 days after completed date
- 14 days after completed date
- 21 days after completed date
- 30 days after completed date (final reminder)

**Conditions:**
- Only for supply jobs with `status = 'completed_pending_rating'`
- Only if `completed_at` is set and in the past
- Reminders stop only once renter submits a rating (not when they skip). The renter can skip unlimited times; each time only records the skip and does not change job status. Reminders are still sent on the schedule above (7, 14, 21, 30 days) so the renter can rate later.
- Each reminder is sent only once (tracked in `supply_job_rating_reminders` table)

**Command Class:** `App\Console\Commands\SendRenterRatingReminders`

---

## Schedule Configuration

**File:** `routes/console.php`

```php
// Provider completion reminders: 2, 7, 14, 21, 30 days after unpack date
Schedule::command('supply-jobs:send-completion-reminders')->daily();

// Renter rating reminders: every 7 days (7, 14, 21, 30 days after completed date)
Schedule::command('supply-jobs:send-renter-rating-reminders')->daily();
```

**Note:** Both commands run **daily**, but they internally check if reminders need to be sent based on the number of days elapsed.

---

## Testing Commands

### Test Scheduler Manually

```bash
# Run scheduler manually (will execute due tasks)
php artisan schedule:run

# List all scheduled tasks
php artisan schedule:list
```

### Test Individual Commands

```bash
# Test provider completion reminders
php artisan supply-jobs:send-completion-reminders

# Test renter rating reminders
php artisan supply-jobs:send-renter-rating-reminders
```

### Check Cron Logs

```bash
# View cron logs (if logging is enabled)
tail -f storage/logs/laravel.log

# Check system cron logs
tail -f /var/log/cron
# or
grep CRON /var/log/syslog
```

---

## Verification Steps

### 1. Verify Cron Job is Running

```bash
# Check if cron job exists
crontab -l

# Should show:
# * * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
```

### 2. Verify Scheduler is Working

```bash
# Run scheduler manually and check output
php artisan schedule:run -v

# Should show which commands ran (if any were due)
```

### 3. Check Scheduled Tasks List

```bash
# List all scheduled tasks
php artisan schedule:list

# Should show:
# supply-jobs:send-completion-reminders    Daily at 00:00
# supply-jobs:send-renter-rating-reminders Daily at 00:00
```

### 4. Test Email Sending

```bash
# Run commands manually and check email logs
php artisan supply-jobs:send-completion-reminders
php artisan supply-jobs:send-renter-rating-reminders

# Check mail logs or test email delivery
```

---

## Troubleshooting

### Issue: Cron job not running

**Check:**
1. Is cron service running?
   ```bash
   sudo service cron status
   # or
   sudo systemctl status cron
   ```

2. Does the path exist?
   ```bash
   ls -la /path-to-your-app
   ```

3. Does PHP exist at that path?
   ```bash
   which php
   php -v
   ```

4. Check cron logs:
   ```bash
   grep CRON /var/log/syslog | tail -20
   ```

### Issue: Commands not executing

**Check:**
1. Run scheduler manually:
   ```bash
   php artisan schedule:run -v
   ```

2. Check Laravel logs:
   ```bash
   tail -f storage/logs/laravel.log
   ```

3. Verify file permissions:
   ```bash
   ls -la app/Console/Commands/
   ```

### Issue: Emails not sending

**Check:**
1. Email configuration in `.env`:
   ```
   MAIL_MAILER=smtp
   MAIL_HOST=...
   MAIL_PORT=...
   MAIL_USERNAME=...
   MAIL_PASSWORD=...
   ```

2. Test email sending:
   ```bash
   php artisan tinker
   Mail::raw('Test', function($msg) { $msg->to('test@example.com')->subject('Test'); });
   ```

3. Check mail queue (if using queue):
   ```bash
   php artisan queue:work
   ```

---

## Schedule Timing

- **Scheduler runs:** Every minute (via cron)
- **Commands execute:** Once per day (at 00:00 server time)
- **Reminder logic:** Commands check daily if reminders are due based on days elapsed

**Example:**
- Job completed on Jan 1
- Scheduler runs daily
- On Jan 8 (7 days later), first renter reminder is sent
- On Jan 15 (14 days later), second reminder is sent
- And so on...

---

## Important Notes

1. **Single Cron Entry:** Only ONE cron entry is needed. Laravel scheduler handles the rest.

2. **Timezone:** Commands run at server timezone. Ensure server timezone is correct:
   ```bash
   date
   # Set timezone in config/app.php: 'timezone' => 'UTC'
   ```

3. **Email Configuration:** Ensure `.env` has correct email settings for reminders to work.

4. **Database:** Ensure migrations are run so reminder tracking tables exist:
   - `supply_job_completion_reminders`
   - `supply_job_rating_reminders`

5. **Permissions:** Ensure Laravel can write logs and cache:
   ```bash
   chmod -R 775 storage bootstrap/cache
   chown -R www-data:www-data storage bootstrap/cache
   ```

---

## Quick Setup Checklist

- [ ] Add cron job: `* * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1`
- [ ] Verify cron job: `crontab -l`
- [ ] Test scheduler: `php artisan schedule:run`
- [ ] Check scheduled tasks: `php artisan schedule:list`
- [ ] Verify email config in `.env`
- [ ] Run migrations (if not done)
- [ ] Test commands manually: `php artisan supply-jobs:send-completion-reminders`
- [ ] Monitor logs: `tail -f storage/logs/laravel.log`

---

## Summary

**Cron Job Command:**
```bash
* * * * * cd /path-to-your-app && php artisan schedule:run >> /dev/null 2>&1
```

**Scheduled Commands:**
1. `supply-jobs:send-completion-reminders` - Daily
2. `supply-jobs:send-renter-rating-reminders` - Daily

**Frequency:** Scheduler runs every minute, commands execute daily when due.
