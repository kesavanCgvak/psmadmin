<?php

/**
 * Laravel Scheduler Cron Script for Shared Hosting
 * 
 * This script runs Laravel's scheduled tasks.
 * Set this file as executable and point your cron job to it.
 * 
 * INSTRUCTIONS:
 * 1. Adjust the $projectPath variable below to match your server path
 * 2. Upload this file to your Laravel project root (same folder as artisan, composer.json)
 * 3. Set up cron job in cPanel: * * * * * /usr/bin/php /path/to/cron.php
 */

// ============================================
// CONFIGURATION - ADJUST THIS PATH
// ============================================
// Option 1: Use __DIR__ (recommended if cron.php is in project root)
$projectPath = __DIR__;

// Option 2: Use absolute path (if __DIR__ doesn't work)
// Uncomment and adjust the path below:
// $projectPath = '/home/username/public_html/psmadmin';
// $projectPath = '/var/www/vhosts/domain.com/public_html/psmadmin';

// ============================================
// BOOTSTRAP LARAVEL
// ============================================

// Load Composer autoloader
require $projectPath . '/vendor/autoload.php';

// Bootstrap Laravel application
$app = require_once $projectPath . '/bootstrap/app.php';

// Make the Kernel (console commands)
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

// Run the schedule:run command
$status = $kernel->call('schedule:run');

// Output the status or any output from the command
echo $kernel->output();

// Exit with the status code
exit($status);
