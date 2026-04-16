# How Laravel Scheduler Triggers Jobs - Detailed Explanation

## Complete Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. CRON JOB (System Level)                                      │
│    Runs every minute: * * * * *                                 │
│    Command: php artisan schedule:run                            │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│ 2. LARAVEL SCHEDULER                                            │
│    Checks routes/console.php for scheduled tasks                │
│    Determines which tasks are due to run                        │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│ 3. SCHEDULED COMMANDS (routes/console.php)                      │
│    • supply-jobs:send-completion-reminders ->daily()           │
│    • supply-jobs:send-renter-rating-reminders ->daily()        │
│    →daily() means: run once per day (at 00:00)                 │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│ 4. COMMAND EXECUTION                                            │
│    Laravel executes the command if it's due                     │
│    Example: SendRenterRatingReminders::handle()                │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│ 5. COMMAND LOGIC (Inside handle() method)                       │
│    • Queries database for jobs needing reminders                │
│    • Checks if reminders are due based on dates                 │
│    • Checks if reminders already sent (prevents duplicates)     │
│    • Sends emails if conditions met                            │
│    • Records sent reminders in database                         │
└─────────────────────────────────────────────────────────────────┘
```

---

## Step-by-Step Explanation

### Step 1: Cron Job Runs (Every Minute)

**System Level:**
```bash
* * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
```

**What happens:**
- Linux cron daemon runs this command **every minute**
- It changes directory to your app
- Executes `php artisan schedule:run`
- Output is redirected to `/dev/null` (discarded)

**Frequency:** Every 60 seconds

---

### Step 2: Laravel Scheduler Checks Tasks

**File:** `routes/console.php`

**What Laravel does:**
1. Loads the scheduler configuration
2. Reads all `Schedule::command()` definitions
3. Checks current time
4. Determines which tasks are due to run

**Your scheduled tasks:**
```php
Schedule::command('supply-jobs:send-completion-reminders')->daily();
Schedule::command('supply-jobs:send-renter-rating-reminders')->daily();
```

**What `->daily()` means:**
- Task runs **once per day**
- Default time: **00:00 (midnight)**
- Laravel checks: "Is it midnight? If yes, run this command."

---

### Step 3: Command Execution (If Due)

**If it's time to run:**

Laravel executes:
```bash
php artisan supply-jobs:send-completion-reminders
# or
php artisan supply-jobs:send-renter-rating-reminders
```

**Command class loaded:**
- `App\Console\Commands\SendSupplyJobCompletionReminders`
- `App\Console\Commands\SendRenterRatingReminders`

---

### Step 4: Command Logic Runs

**Inside `handle()` method:**

#### Example: SendRenterRatingReminders

```php
public function handle(): int
{
    // 1. Get today's date
    $today = Carbon::today();
    
    // 2. Query database for jobs needing reminders
    $jobs = SupplyJob::where('status', 'completed_pending_rating')
        ->whereNotNull('completed_at')
        ->get();
    
    // 3. For each job, check if reminders are due
    foreach ($jobs as $supplyJob) {
        // Skip if already rated
        if ($supplyJob->jobRating && $supplyJob->jobRating->rated_at) {
            continue;
        }
        
        // Calculate days since completed
        $completedAt = Carbon::parse($supplyJob->completed_at);
        $daysSinceCompleted = $completedAt->diffInDays($today);
        
        // Check which reminders need to be sent
        foreach ([7, 14, 21, 30] as $days) {
            // Check if this reminder is due
            if ($daysSinceCompleted >= $days) {
                // Check if already sent
                if (!alreadySent($supplyJob, $days)) {
                    // Send email
                    sendReminderEmail($supplyJob, $days);
                    // Record in database
                    recordReminderSent($supplyJob, $days);
                }
            }
        }
    }
}
```

---

## Detailed Example Timeline

### Scenario: Provider marks job completed on January 1st

**January 1, 00:00:**
- Provider marks job as completed
- `completed_at` = `2025-01-01 00:00:00`
- Status = `completed_pending_rating`
- Initial email sent immediately (not via scheduler)

**January 1-7:**
- Scheduler runs daily
- Command executes daily
- Checks: "7 days since completed?" → No (only 1-6 days)
- **No reminders sent**

**January 8, 00:00:**
- Scheduler runs
- Command executes
- Checks: "7 days since completed?" → **Yes** (7 days = Jan 8)
- Checks: "Already sent?" → No
- **Sends first reminder email** (7 days)
- Records in `supply_job_rating_reminders`: `days_after_completed = 7`

**January 9-13:**
- Scheduler runs daily
- Command executes daily
- Checks: "14 days since completed?" → No (only 8-13 days)
- Checks: "7 days reminder?" → Already sent
- **No new reminders**

**January 15, 00:00:**
- Scheduler runs
- Command executes
- Checks: "14 days since completed?" → **Yes** (14 days = Jan 15)
- Checks: "Already sent?" → No
- **Sends second reminder email** (14 days)
- Records: `days_after_completed = 14`

**And so on...**

---

## Key Points

### 1. Cron Runs Every Minute, Commands Run Daily

- **Cron:** Runs `schedule:run` every minute
- **Scheduler:** Checks if commands are due
- **Commands:** Execute only when scheduled (daily = once per day)

### 2. Commands Check Database, Not Time

The commands don't just run blindly. They:
- Query database for relevant jobs
- Calculate days elapsed
- Check if reminders are due
- Verify reminders weren't already sent
- Only then send emails

### 3. Duplicate Prevention

**How duplicates are prevented:**
- Database table tracks sent reminders
- Before sending, checks if reminder already sent
- Example: `supply_job_rating_reminders` table stores:
  - `supply_job_id`
  - `days_after_completed` (7, 14, 21, or 30)
  - `sent_at` timestamp

### 4. Smart Logic Inside Commands

**The commands are smart:**
- They check multiple conditions
- Skip jobs that don't need reminders
- Only send when exactly due
- Record what was sent

---

## Visual Timeline Example

```
Day 0: Job completed (Jan 1)
        ↓
        Initial email sent immediately
        ↓
Day 1-6: Scheduler runs daily
        ↓
        Command executes but finds no reminders due
        ↓
Day 7: Scheduler runs
        ↓
        Command executes
        ↓
        Finds: 7 days elapsed → Sends reminder
        ↓
        Records: reminder sent for day 7
        ↓
Day 8-13: Scheduler runs daily
        ↓
        Command executes but finds no new reminders due
        ↓
Day 14: Scheduler runs
        ↓
        Command executes
        ↓
        Finds: 14 days elapsed → Sends reminder
        ↓
        Records: reminder sent for day 14
```

---

## Testing the Flow

### 1. Test Scheduler Manually

```bash
# This simulates what cron does every minute
php artisan schedule:run

# Output shows which commands ran (if any were due)
```

### 2. Test Individual Commands

```bash
# Run command directly (bypasses scheduler timing)
php artisan supply-jobs:send-renter-rating-reminders

# This will check database and send reminders if due
```

### 3. Check What Would Run

```bash
# List all scheduled tasks
php artisan schedule:list

# Shows:
# supply-jobs:send-completion-reminders    Daily at 00:00
# supply-jobs:send-renter-rating-reminders Daily at 00:00
```

---

## Summary

**Trigger Flow:**
1. **Cron** (every minute) → Runs `php artisan schedule:run`
2. **Scheduler** → Checks `routes/console.php` for scheduled tasks
3. **Due Check** → "Is it time to run this command?" (daily = midnight)
4. **Command Execution** → If due, runs the command
5. **Command Logic** → Queries database, checks conditions, sends emails
6. **Recording** → Saves sent reminders to prevent duplicates

**Key Insight:** The cron runs frequently (every minute), but the commands themselves run only when scheduled (daily). The commands are smart - they check the database to determine what needs to be done, rather than blindly executing.
