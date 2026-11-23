
<?php

/**
 * Laravel 12 Scheduler - DreamHost Shared Hosting
 */

// Laravel project root
$projectPath = '/home/prosubmarket/prosubmarket.cgstagingsite.com';

// Change to Laravel project directory
chdir($projectPath);

// Verify project path
if (!file_exists($projectPath . '/artisan')) {
    error_log('Laravel artisan file not found: ' . $projectPath);
    exit(1);
}

// Load Composer autoloader
require $projectPath . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once $projectPath . '/bootstrap/app.php';

// Execute Laravel scheduler
$_SERVER['argv'] = ['artisan', 'schedule:run'];

$input = new Symfony\Component\Console\Input\ArgvInput();

$status = $app->handleCommand($input);

// Exit with Laravel command status
exit($status);
