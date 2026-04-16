# SupplyJob $casts Redeclaration Error - Analysis

## Error Details

**Error:** `FatalError: Cannot redeclare App\Models\SupplyJob::$casts`  
**Location:** `app/Models/SupplyJob.php` line 37  
**Environment:** Server (prosubmarket.cgstagingsite.com) - PHP 8.3.27, Laravel 12.28.1  
**Local Status:** ✅ Working fine  
**Server Status:** ❌ Fatal error

---

## Root Cause Analysis

The error indicates that the `$casts` property is being declared **twice** in the `SupplyJob` model on the server. Your local file only has **one** declaration (lines 32-34), but the server version likely has **two**.

---

## Possible Causes

### 1. **Deployment/Merge Conflict** (Most Likely)

**Scenario:** The server has an older version of `SupplyJob.php` that already had a `$casts` property defined. When you deployed the new code (which added `completed_at` to `$casts`), both declarations ended up in the file.

**What happened:**
- **Server's old version** had: `protected $casts = [...existing casts...];` (maybe around line 30-35)
- **Your new code** added: `protected $casts = ['completed_at' => 'datetime'];` (line 32-34)
- **Result:** Both declarations exist → Fatal error

**Evidence:**
- Error says redeclaration at line 37
- Your local file has `$casts` at line 32
- This suggests the server file has `$casts` at an earlier line AND another one at line 37

---

### 2. **Git Merge Conflict Not Resolved**

**Scenario:** During a merge, Git detected a conflict in `SupplyJob.php` where both branches modified `$casts`. The conflict markers (`<<<<<<<`, `=======`, `>>>>>>>`) were removed, but both versions of `$casts` were kept.

**What to check on server:**
```bash
# Check if there are conflict markers still in the file
grep -n "<<<<<<<" app/Models/SupplyJob.php
grep -n "=======" app/Models/SupplyJob.php
grep -n ">>>>>>>" app/Models/SupplyJob.php
```

---

### 3. **Stale Code Deployment**

**Scenario:** The deployment process didn't fully overwrite the old file, or there was a partial deployment failure.

**What happened:**
- Old file had `$casts` with other fields (e.g., `handshake_status`, `cancelled_by`, etc.)
- New deployment added another `$casts` block
- Both blocks exist in the deployed file

---

### 4. **PHP OPcache / Composer Autoloader Cache**

**Scenario:** The server's PHP OPcache or Composer's autoloader cache is serving an old, cached version of the class that conflicts with the new file.

**Less likely but possible:**
- Old cached class definition has `$casts`
- New file also has `$casts`
- PHP tries to load both → conflict

---

## What to Check on Server

### Step 1: View the Actual File on Server

```bash
# SSH into server and check the file
cat app/Models/SupplyJob.php | grep -A 5 -B 5 "protected \$casts"
```

**Look for:**
- How many times `protected $casts` appears
- What line numbers they're on
- What fields are in each declaration

---

### Step 2: Check for Duplicate Declarations

```bash
# Count how many times $casts is declared
grep -c "protected \$casts" app/Models/SupplyJob.php

# Should be 1, if it's 2 or more → that's the problem
```

---

### Step 3: Check Git Status

```bash
# On server, check if file has uncommitted changes
git status app/Models/SupplyJob.php

# Check git log to see recent changes
git log --oneline app/Models/SupplyJob.php | head -5
```

---

### Step 4: Compare Server vs Local

```bash
# On your local machine, generate a diff
# Then compare with server version

# On server, check the exact content around line 37
sed -n '25,45p' app/Models/SupplyJob.php
```

---

## Expected File Structure (Correct)

The `SupplyJob.php` file should have **exactly ONE** `$casts` declaration:

```php
protected $fillable = [
    // ... fields ...
    'completed_at',
    // ... more fields ...
];

protected $casts = [
    'completed_at' => 'datetime',
    // If server had other casts, they should be merged here:
    // 'packing_date' => 'date',
    // 'delivery_date' => 'date',
    // 'return_date' => 'date',
    // 'unpacking_date' => 'date',
];

// ... rest of the class ...
```

**Important:** If the server version had other casts (like date fields), they need to be **merged** into a single `$casts` array, not kept as separate declarations.

---

## What the Server File Probably Looks Like (Incorrect)

The server likely has **TWO** declarations:

```php
// First declaration (old, maybe around line 30)
protected $casts = [
    'packing_date' => 'date',           // or 'datetime'
    'delivery_date' => 'date',          // or 'datetime'
    'return_date' => 'date',            // or 'datetime'
    'unpacking_date' => 'date',         // or 'datetime'
    'handshake_status' => 'string',
    'cancelled_by' => 'integer',
    // ... other old casts ...
];

// ... some code ...

// Second declaration (new, line 37)
protected $casts = [
    'completed_at' => 'datetime',
];
```

**Note:** The model has date fields (`packing_date`, `delivery_date`, `return_date`, `unpacking_date`) that might have been cast as dates in the server version. When `completed_at` was added, a new `$casts` block was created instead of merging with the existing one.

---

## Solutions (Without Code Changes)

### Solution 1: Check Server File Content

**Action:** SSH into server and view the actual file:
```bash
cat app/Models/SupplyJob.php
```

**Look for:** Multiple `protected $casts` declarations. If you find two, you need to merge them into one.

---

### Solution 2: Clear Caches on Server

**Action:** Clear all Laravel and PHP caches:
```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
composer dump-autoload

# If using OPcache
php artisan opcache:clear  # if you have this command
# Or restart PHP-FPM
sudo service php8.3-fpm restart  # adjust version
```

---

### Solution 3: Redeploy the File

**Action:** Ensure the correct version of `SupplyJob.php` is deployed:
```bash
# On server, backup current file
cp app/Models/SupplyJob.php app/Models/SupplyJob.php.backup

# Then redeploy the correct version from your local
# (via git pull, deployment script, or manual copy)
```

---

### Solution 4: Check Deployment Process

**Action:** Review your deployment process:
- Does it fully overwrite files?
- Are there any merge conflicts that weren't resolved?
- Is the deployment script correctly copying files?

---

## Prevention

1. **Always check for conflicts** before deploying
2. **Use Git properly** - resolve all merge conflicts before committing
3. **Clear caches** after deployment
4. **Test on staging** before production
5. **Use version control** - ensure server code matches your repository

---

## Quick Diagnostic Commands (Run on Server)

```bash
# 1. Check for duplicate $casts
grep -n "protected \$casts" app/Models/SupplyJob.php

# 2. View the file around line 37
sed -n '30,45p' app/Models/SupplyJob.php

# 3. Check file size/modification time
ls -lh app/Models/SupplyJob.php

# 4. Compare with git version
git diff HEAD app/Models/SupplyJob.php

# 5. Check for syntax errors
php -l app/Models/SupplyJob.php
```

---

## Summary

**Most Likely Cause:** The server file has **two** `protected $casts` declarations - one from an older version and one from your recent changes. The old one probably includes other casts (like `handshake_status`, `cancelled_by`, etc.), and your new one only has `completed_at`.

**Fix:** Merge both `$casts` arrays into a single declaration that includes all casts.

**Why it works locally:** Your local file only has one `$casts` declaration (the correct one).

**Next Step:** SSH into the server, check the actual file content, and merge the duplicate `$casts` declarations into one.
