<?php

namespace App\Console\Commands;

use App\Models\EmailTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class RefreshEmailTemplateFromBlade extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-template:refresh-from-blade {name? : Template name (e.g. forgotPassword). If omitted, refreshes forgotPassword.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh email template body in database from the blade file (e.g. to apply button styles).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->argument('name') ?? 'forgotPassword';
        $file = $name === 'support-request' || $name === 'contact-sales' || $name === 'new-admin-user'
            ? $name . '.blade.php'
            : $name . '.blade.php';
        $path = resource_path('views/emails/' . $file);

        if (!File::exists($path)) {
            $this->error("Blade file not found: {$path}");
            return self::FAILURE;
        }

        $template = EmailTemplate::where('name', $name)->first();
        if (!$template) {
            $this->error("Database template not found for: {$name}");
            return self::FAILURE;
        }

        $body = File::get($path);
        $template->body = $body;
        $template->save();

        $this->info("Refreshed template '{$name}' from blade file. Button styles are now in the database.");
        return self::SUCCESS;
    }
}
