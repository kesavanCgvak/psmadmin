<?php

namespace App\Services;

use App\Models\EmailTemplate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Log;

class EmailTemplateService
{
    /**
     * Get email template content from database or fallback to blade file.
     *
     * @param string $templateName Template identifier (e.g., 'registrationSuccess')
     * @param array $data Data to pass to template
     * @return array Returns ['subject' => string, 'body' => string] or null if template not found/disabled
     */
    public function getTemplate(string $templateName, array $data = []): ?array
    {
        // Check if template exists in database (regardless of active status)
        $templateInDb = EmailTemplate::where('name', $templateName)->first();
        
        if ($templateInDb) {
            // Template exists in database
            if (!$templateInDb->is_active) {
                // Template is disabled - do not send email
                Log::info('Email template is disabled, skipping email send', [
                    'template' => $templateName,
                ]);
                return null; // Return null to indicate email should not be sent
            }
            
            // Template is active - use it
            return [
                'subject' => $this->replaceVariables($templateInDb->subject, $data),
                'body' => $this->replaceVariables($templateInDb->body, $data),
            ];
        }

        // Template doesn't exist in database - fallback to blade file
        $bladePath = "emails.{$templateName}";
        
        if (View::exists($bladePath)) {
            try {
                $body = view($bladePath, $data)->render();
                
                // Try to get subject from config or use default
                $subject = $this->getDefaultSubject($templateName);
                
                return [
                    'subject' => $this->replaceVariables($subject, $data),
                    'body' => $body,
                ];
            } catch (\Exception $e) {
                Log::error('Failed to render email template', [
                    'template' => $bladePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::warning('Email template not found', [
            'template' => $templateName,
        ]);

        return null;
    }

    /**
     * Replace variables in template content.
     *
     * @param string $content Template content
     * @param array $data Variables to replace
     * @return string Content with variables replaced
     */
    private function replaceVariables(string $content, array $data): string
    {
        // First: replace env() and config() placeholders (Blade syntax in DB templates)
        $content = $this->replaceEnvAndConfigPlaceholders($content);

        foreach ($data as $key => $value) {
            // Convert value to string if needed
            $stringValue = is_array($value) ? json_encode($value) : (string) $value;
            
            // Replace {{variable}} format
            $content = str_replace('{{' . $key . '}}', $stringValue, $content);
            // Replace {{ $variable }} format (blade syntax with spaces)
            $content = str_replace('{{ $' . $key . ' }}', $stringValue, $content);
            // Replace {{$variable}} format (blade syntax without spaces)
            $content = str_replace('{{$' . $key . '}}', $stringValue, $content);
            // Replace {{ $variable}} format (mixed)
            $content = str_replace('{{ $' . $key . '}}', $stringValue, $content);
            // Replace {{$variable }} format (mixed)
            $content = str_replace('{{$' . $key . ' }}', $stringValue, $content);
        }

        return $content;
    }

    /**
     * Replace env() and config() placeholders in template content.
     * Database templates store Blade syntax as text; these are not executed,
     * so we replace common placeholders with actual values.
     *
     * @param string $content Template content
     * @return string Content with placeholders replaced
     */
    private function replaceEnvAndConfigPlaceholders(string $content): string
    {
        $appFrontendUrl = rtrim(env('APP_FRONTEND_URL', config('app.url', '')), '/');
        $appUrl = rtrim(config('app.url', env('APP_URL', '')), '/');

        $replacements = [
            // env('APP_FRONTEND_URL') - various quote/spacing forms
            "{{ env('APP_FRONTEND_URL') }}"   => $appFrontendUrl,
            '{{ env(\'APP_FRONTEND_URL\') }}' => $appFrontendUrl,
            '{{env(\'APP_FRONTEND_URL\')}}'   => $appFrontendUrl,
            "{{env('APP_FRONTEND_URL')}}"    => $appFrontendUrl,
            // env('APP_URL')
            "{{ env('APP_URL') }}"            => $appUrl,
            '{{ env(\'APP_URL\') }}'          => $appUrl,
            '{{env(\'APP_URL\')}}'            => $appUrl,
            // config('app.url')
            "{{ config('app.url') }}"         => $appUrl,
            '{{ config(\'app.url\') }}'       => $appUrl,
        ];

        foreach ($replacements as $placeholder => $value) {
            $content = str_replace($placeholder, $value ?? '', $content);
        }

        return $content;
    }

    /**
     * Get default subject for template if not in database.
     *
     * @param string $templateName
     * @return string
     */
    private function getDefaultSubject(string $templateName): string
    {
        $defaultSubjects = [
            'registrationSuccess' => 'Welcome to ProSub Marketplace - Account Created Successfully',
            'forgotPassword' => 'Password Reset - Pro Subrental Marketplace',
            'verificationEmail' => 'Email Verification - ProSub Marketplace',
            'jobCompletionReminder' => 'Reminder: Please complete this job',
            'jobRatingReminder' => 'Reminder: Please rate this job',
            'jobRatingRequest' => 'Rate Your Experience',
            'rentalJobOffer' => 'New Offer Received from Pro Subrental Marketplace',
            'supplyNewOffer' => 'New Supply Offer Received',
            'rentalJobCancelled' => 'Rental Job Cancelled',
            'supplyJobCancelled' => 'Supply Job Cancelled',
            'jobHandshakeAccepted' => 'Job Handshake Accepted',
            'subscriptionCreated' => 'Subscription Created',
            'subscriptionCanceled' => 'Subscription Cancelled',
            'support-request' => 'New Support Request',
            'contact-sales' => 'New Contact Sales Inquiry',
            'new-admin-user' => 'Welcome to PSM Admin Panel',
        ];

        return $defaultSubjects[$templateName] ?? 'Notification from Pro Subrental Marketplace';
    }

    /**
     * Check if template exists in database.
     *
     * @param string $templateName
     * @return bool
     */
    public function existsInDatabase(string $templateName): bool
    {
        return EmailTemplate::where('name', $templateName)->exists();
    }

    /**
     * Get all available template names (from database and blade files).
     *
     * @return array
     */
    public function getAllTemplateNames(): array
    {
        $dbTemplates = EmailTemplate::pluck('name')->toArray();
        
        // Get blade templates from resources/views/emails directory
        $bladeTemplates = [];
        $emailsPath = resource_path('views/emails');
        
        if (is_dir($emailsPath)) {
            $files = glob($emailsPath . '/*.blade.php');
            foreach ($files as $file) {
                $name = basename($file, '.blade.php');
                if (!in_array($name, $dbTemplates)) {
                    $bladeTemplates[] = $name;
                }
            }
        }

        return [
            'database' => $dbTemplates,
            'blade' => $bladeTemplates,
        ];
    }
}
