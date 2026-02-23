# Shared Hosting Cron Setup Guide

For shared hosting environments where you can't directly run `php artisan` commands, use a PHP script that Laravel's scheduler can execute.

---

## PHP Script for Cron Job

Create a file named `cron.php` in your Laravel project root directory:

**File:** `cron.php` (in project root, same level as `artisan`)

```php
<?php

/**
 * Laravel Scheduler Cron Script for Shared Hosting
 * 
 * This script runs Laravel's scheduled tasks.
 * Set this file as executable and point your cron job to it.
 */

// Adjust this path to point to your Laravel project's vendor/autoload.php
// Example: /home/username/public_html/psmadmin/vendor/autoload.php
// Example: /var/www/vhosts/domain.com/public_html/psmadmin/vendor/autoload.php
require __DIR__ . '/vendor/autoload.php';

// Bootstrap the Laravel application
// Adjust path if needed, but __DIR__ should work if cron.php is in project root
$app = require_once __DIR__ . '/bootstrap/app.php';

// Make the Kernel (console commands)
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

// Run the schedule:run command
$status = $kernel->call('schedule:run');

// Output the status or any output from the command
echo $kernel->output();

// Exit with the status code
exit($status);
```

---

## Alternative: Using Absolute Paths

If `__DIR__` doesn't work on your shared host, use absolute paths:

```php
<?php

/**
 * Laravel Scheduler Cron Script for Shared Hosting
 * Replace the paths below with your actual server paths
 */

// Example paths (REPLACE WITH YOUR ACTUAL PATHS):
// /home/username/public_html/psmadmin
// /var/www/vhosts/domain.com/public_html/psmadmin
// /home/domain/public_html/psmadmin

$projectPath = '/home/username/public_html/psmadmin'; // CHANGE THIS

require $projectPath . '/vendor/autoload.php';

$app = require_once $projectPath . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

$status = $kernel->call('schedule:run');

echo $kernel->output();

exit($status);
```

---

## How to Find Your Project Path

### Method 1: Check Current Directory

Create a test file `test-path.php`:

```php
<?php
echo __DIR__;
echo "\n";
echo getcwd();
```

Upload to your project root and access via browser. This shows your path.

### Method 2: Check cPanel File Manager

1. Log into cPanel
2. Go to **File Manager**
3. Navigate to your Laravel project root
4. Check the path shown in the address bar
5. Usually looks like: `/home/username/public_html/psmadmin` or `/home/username/psmadmin`

### Method 3: Check .htaccess or index.php

Look at your existing files - they might have paths:
- Check `public/index.php` - it might have `require __DIR__.'/../vendor/autoload.php';`
- Check `.htaccess` files

---

## Setting Up Cron Job in Shared Hosting

### Method 1: cPanel Cron Jobs

1. **Log into cPanel**
2. **Go to "Cron Jobs"** (under Advanced section)
3. **Add New Cron Job:**
   - **Minute:** `*`
   - **Hour:** `*`
   - **Day:** `*`
   - **Month:** `*`
   - **Weekday:** `*`
   - **Command:** 
     ```bash
     /usr/bin/php /home/username/public_html/psmadmin/cron.php
     ```
     OR
     ```bash
     php /home/username/public_html/psmadmin/cron.php
     ```

4. **Save**

**Note:** Replace `/home/username/public_html/psmadmin` with your actual path.

---

### Method 2: Direct PHP Path

Some hosts require full PHP path. Find it:

```bash
which php
# or
whereis php
```

Common paths:
- `/usr/bin/php`
- `/usr/local/bin/php`
- `/opt/cpanel/ea-php81/root/usr/bin/php` (cPanel EasyApache)
- `/opt/cpanel/ea-php82/root/usr/bin/php`

**Example cron command:**
```bash
/usr/bin/php /home/username/public_html/psmadmin/cron.php
```

---

### Method 3: Using wget or curl

If PHP CLI doesn't work, use HTTP request:

```bash
wget -q -O - https://yourdomain.com/cron.php > /dev/null 2>&1
```

OR

```bash
curl -s https://yourdomain.com/cron.php > /dev/null 2>&1
```

**Note:** This requires `cron.php` to be accessible via web (in `public/` folder or with proper routing).

---

## File Permissions

Make sure `cron.php` has correct permissions:

```bash
chmod 755 cron.php
# or
chmod 644 cron.php
```

---

## Testing the Script

### Test 1: Run via Browser

1. Upload `cron.php` to your project root
2. Access via browser: `https://yourdomain.com/cron.php`
3. Should see output (or blank if no tasks due)

### Test 2: Run via SSH (if available)

```bash
cd /home/username/public_html/psmadmin
php cron.php
```

### Test 3: Check Laravel Logs

After running, check:
```bash
tail -f storage/logs/laravel.log
```

---

## Complete Example Setup

### Step 1: Create `cron.php` in Project Root

```php
<?php

// Your actual project path (get from cPanel File Manager)
$projectPath = '/home/yourusername/public_html/psmadmin';

require $projectPath . '/vendor/autoload.php';
$app = require_once $projectPath . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$status = $kernel->call('schedule:run');
echo $kernel->output();
exit($status);
```

### Step 2: Upload to Server

Upload `cron.php` to your Laravel project root (same folder as `artisan`, `composer.json`, etc.)

### Step 3: Set Up Cron Job in cPanel

1. Go to **Cron Jobs** in cPanel
2. Add:
   ```
   * * * * * /usr/bin/php /home/yourusername/public_html/psmadmin/cron.php
   ```
3. Save

### Step 4: Verify

- Check cron logs in cPanel
- Check Laravel logs: `storage/logs/laravel.log`
- Test manually by accessing `cron.php` via browser

---

## Troubleshooting

### Issue: "No such file or directory"

**Problem:** Path is incorrect

**Solution:**
- Verify path in cPanel File Manager
- Use absolute paths, not relative
- Check if path has spaces (wrap in quotes)

### Issue: "Permission denied"

**Problem:** File permissions or PHP path wrong

**Solution:**
```bash
chmod 755 cron.php
# Try different PHP paths:
/usr/bin/php
/usr/local/bin/php
php (if in PATH)
```

### Issue: Script runs but no output

**Problem:** Normal if no tasks are due

**Solution:**
- Check Laravel logs: `storage/logs/laravel.log`
- Run command manually: `php cron.php`
- Add debug output to script

### Issue: "Class not found" or autoload errors

**Problem:** Paths incorrect or vendor not installed

**Solution:**
- Verify `vendor/autoload.php` exists
- Run `composer install` on server
- Check paths are correct

---

## Debug Version (For Testing)

Add this to see what's happening:

```php
<?php

$projectPath = '/home/username/public_html/psmadmin'; // CHANGE THIS

echo "Starting cron...\n";
echo "Project path: $projectPath\n";

if (!file_exists($projectPath . '/vendor/autoload.php')) {
    die("Error: vendor/autoload.php not found at: " . $projectPath . "/vendor/autoload.php\n");
}

require $projectPath . '/vendor/autoload.php';
echo "Autoload loaded\n";

if (!file_exists($projectPath . '/bootstrap/app.php')) {
    die("Error: bootstrap/app.php not found\n");
}

$app = require_once $projectPath . '/bootstrap/app.php';
echo "App bootstrapped\n";

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
echo "Kernel created\n";

echo "Running schedule:run...\n";
$status = $kernel->call('schedule:run');

echo "Status: $status\n";
echo "Output:\n";
echo $kernel->output();

exit($status);
```

---

## Security Note

**Important:** If placing `cron.php` in `public/` folder (web-accessible):

1. Add IP restriction in `.htaccess`:
   ```apache
   <Files "cron.php">
       Order Deny,Allow
       Deny from all
       Allow from 127.0.0.1
       Allow from ::1
   </Files>
   ```

2. Or add authentication:
   ```php
   <?php
   // Add at top of cron.php
   if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1' && $_SERVER['REMOTE_ADDR'] !== '::1') {
       die('Access denied');
   }
   // ... rest of script
   ```

**Better:** Keep `cron.php` in project root (not in `public/`) so it's not web-accessible.

---

## Summary

1. **Create `cron.php`** in project root with Laravel bootstrap code
2. **Adjust paths** to match your server
3. **Set up cron job** in cPanel pointing to the PHP script
4. **Test** by accessing script or checking logs
5. **Monitor** Laravel logs to verify it's working

**Cron Command Format:**
```bash
* * * * * /usr/bin/php /home/username/public_html/psmadmin/cron.php
```

Replace paths with your actual server paths.
