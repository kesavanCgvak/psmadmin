<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel Scheduler Cron Script for Shared Hosting
 *
 * Run this with the PHP CLI. It is not a web route.
 * The site document root is public/, so https://your-domain/cron.php
 * returns 404 before this file executes.
 *
 * cPanel, one existing entry, every minute, PHP 8.3+:
 * * * * * * /usr/local/bin/ea-php83 /absolute/path/to/this/cron.php
 *
 * Temporary diagnostics use the prefix [QUEUE_SCHEDULER_DEBUG].
 * Remove this instrumentation after the 404 is confirmed.
 */

// This file lives in the Laravel project root, next to artisan and vendor.
$projectPath = __DIR__;

// If a copy of cron.php is moved into a subfolder, point at the project root instead:
// $projectPath = dirname(__DIR__);

$queueSchedulerLogFile = $projectPath.'/storage/logs/queue-scheduler-debug.log';

/**
 * @param  array<string, scalar|null>  $context
 */
function queueSchedulerDebug(string $stage, array $context = []): void
{
    global $queueSchedulerLogFile;

    $parts = ['[QUEUE_SCHEDULER_DEBUG]', gmdate('c'), 'stage='.$stage];
    foreach ($context as $key => $value) {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }
        $text = str_replace(["\r", "\n"], ' ', (string) $value);
        $text = preg_replace('/(password|passwd|pwd|secret|token|api[_-]?key)=\S+/i', '$1=[redacted]', $text) ?? $text;
        $parts[] = $key.'='.substr($text, 0, 500);
    }

    $line = implode(' ', $parts);
    error_log($line);

    $directory = dirname((string) $queueSchedulerLogFile);
    if (is_dir($directory) && is_writable($directory)) {
        @file_put_contents($queueSchedulerLogFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function queueSchedulerRedact(string $message): string
{
    $message = preg_replace('/(password|passwd|pwd|secret|token|api[_-]?key)([=:\s]+)\S+/i', '$1$2[redacted]', $message) ?? $message;

    return substr(str_replace(["\r", "\n"], ' ', $message), 0, 500);
}

register_shutdown_function(function (): void {
    $error = error_get_last();
    if (! is_array($error)) {
        return;
    }

    if (! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    queueSchedulerDebug('fatal', [
        'type' => (string) $error['type'],
        'message' => queueSchedulerRedact((string) $error['message']),
        'file' => (string) $error['file'],
        'line' => (string) $error['line'],
    ]);
});

queueSchedulerDebug('script_entered', [
    'script' => __FILE__,
    'cwd' => getcwd() ?: '',
    'project_path' => $projectPath,
    'php_version' => PHP_VERSION,
    'php_binary' => PHP_BINARY,
    'php_sapi' => PHP_SAPI,
    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '',
]);

if (PHP_SAPI !== 'cli') {
    queueSchedulerDebug('http_invocation', [
        'note' => 'This file ran through a web SAPI. A normal /cron.php browser request 404s because the file is outside public/.',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '',
    ]);
}

$autoload = $projectPath.'/vendor/autoload.php';
$bootstrap = $projectPath.'/bootstrap/app.php';
$artisan = $projectPath.'/artisan';
$envFile = $projectPath.'/.env';
$configCache = $projectPath.'/bootstrap/cache/config.php';

queueSchedulerDebug('paths_resolved', [
    'project_path' => $projectPath,
    'artisan_exists' => is_file($artisan),
    'autoload_exists' => is_file($autoload),
    'bootstrap_exists' => is_file($bootstrap),
    'env_exists' => is_file($envFile),
    'config_cache_exists' => is_file($configCache),
]);

try {
    if (! is_file($autoload) || ! is_file($bootstrap)) {
        queueSchedulerDebug('bootstrap_aborted', [
            'reason' => 'autoload_or_bootstrap_missing',
        ]);
        exit(1);
    }

    require $autoload;

    /** @var Application $app */
    $app = require_once $bootstrap;
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    queueSchedulerDebug('laravel_bootstrapped', [
        'environment' => (string) $app->environment(),
        'queue_connection' => (string) config('queue.default'),
        'cache_store' => (string) config('cache.default'),
        'config_cached' => $app->configurationIsCached(),
    ]);

    try {
        Log::info('[QUEUE_SCHEDULER_DEBUG] laravel_bootstrapped', [
            'environment' => $app->environment(),
            'queue_connection' => config('queue.default'),
            'cache_store' => config('cache.default'),
        ]);
    } catch (Throwable $logError) {
        queueSchedulerDebug('laravel_log_unavailable', [
            'exception' => $logError::class,
            'message' => queueSchedulerRedact($logError->getMessage()),
        ]);
    }

    $databaseOk = false;
    $jobsTableExists = false;
    $pendingBefore = null;

    try {
        DB::connection()->getPdo();
        $databaseOk = true;
        $jobsTableExists = Schema::hasTable('jobs');
        if ($jobsTableExists) {
            $pendingBefore = (int) DB::table('jobs')->where('queue', 'default')->count();
        }
    } catch (Throwable $databaseError) {
        queueSchedulerDebug('database_failed', [
            'exception' => $databaseError::class,
            'message' => queueSchedulerRedact($databaseError->getMessage()),
            'file' => $databaseError->getFile(),
            'line' => (string) $databaseError->getLine(),
        ]);
        Log::error('[QUEUE_SCHEDULER_DEBUG] database_failed', [
            'exception' => $databaseError::class,
            'message' => queueSchedulerRedact($databaseError->getMessage()),
        ]);
    }

    $queueWorkerRegistered = false;
    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains((string) $event->command, 'queue:work')) {
            $queueWorkerRegistered = true;
            break;
        }
    }

    queueSchedulerDebug('before_schedule_run', [
        'database_ok' => $databaseOk,
        'jobs_table_exists' => $jobsTableExists,
        'pending_jobs' => $pendingBefore === null ? 'unknown' : (string) $pendingBefore,
        'queue_worker_registered' => $queueWorkerRegistered,
    ]);

    $status = $kernel->call('schedule:run');
    $output = trim($kernel->output());

    $pendingAfter = null;
    if ($databaseOk && $jobsTableExists) {
        $pendingAfter = (int) DB::table('jobs')->where('queue', 'default')->count();
    }

    queueSchedulerDebug('schedule_finished', [
        'exit_status' => (string) $status,
        'pending_jobs_before' => $pendingBefore === null ? 'unknown' : (string) $pendingBefore,
        'pending_jobs_after' => $pendingAfter === null ? 'unknown' : (string) $pendingAfter,
        'output' => $output === '' ? '(empty)' : queueSchedulerRedact($output),
    ]);

    if ($output !== '' && preg_match('/\b404\b|Not Found/i', $output) === 1) {
        queueSchedulerDebug('scheduler_output_contains_404', [
            'output' => queueSchedulerRedact($output),
        ]);
        Log::error('[QUEUE_SCHEDULER_DEBUG] scheduler_output_contains_404');
    }

    echo $output;
    exit($status);
} catch (Throwable $e) {
    queueSchedulerDebug('uncaught_exception', [
        'exception' => $e::class,
        'message' => queueSchedulerRedact($e->getMessage()),
        'file' => $e->getFile(),
        'line' => (string) $e->getLine(),
    ]);

    try {
        Log::error('[QUEUE_SCHEDULER_DEBUG] uncaught_exception', [
            'exception' => $e::class,
            'message' => queueSchedulerRedact($e->getMessage()),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    } catch (Throwable) {
        // The file log above is the record when the Laravel logger is unavailable.
    }

    exit(1);
}
