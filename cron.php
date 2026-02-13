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
// If cron.php is in a SUBFOLDER (e.g. schedulerFiles), point to Laravel project root (parent folder)
$projectPath = dirname(__DIR__);

// If cron.php is in Laravel project ROOT (same folder as artisan, vendor), use:
// $projectPath = __DIR__;

// Or use absolute path (recommended for shared hosting when in subfolder):
// $projectPath = '/home/prosubmarket/prosubmarket.cgstagingsite.com';

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
