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
        // Ensure every template has current_year for footer (so no need to pass from every sender)
        $data = array_merge(['current_year' => (string) date('Y')], $data);

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
            $body = $this->replaceVariables($templateInDb->body, $data);
            $body = $this->ensureFooterYear($body);
            return [
                'subject' => $this->replaceVariables($templateInDb->subject, $data),
                'body' => $body,
            ];
        }

        // Template doesn't exist in database - fallback to blade file
        $bladePath = "emails.{$templateName}";
        
        if (View::exists($bladePath)) {
            try {
                $body = view($bladePath, $data)->render();
                $body = $this->ensureFooterYear($body);
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
            // Replace {!! $variable !!} format (unescaped HTML - so DB templates can output HTML)
            $content = str_replace('{!! $' . $key . ' !!}', $stringValue, $content);
            $content = str_replace('{!!$' . $key . '!!}', $stringValue, $content);
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
            // date('Y') - current year in footers
            "{{ date('Y') }}"                 => (string) date('Y'),
            "{{ date(\'Y\') }}"               => (string) date('Y'),
        ];

        foreach ($replacements as $placeholder => $value) {
            $content = str_replace($placeholder, $value ?? '', $content);
        }

        // {{ date('Y') }} - match any spacing/quote variant so it never shows literally in DB templates
        $currentYear = (string) date('Y');
        $content = preg_replace('/\{\{\s*date\s*\(\s*[\'"]Y[\'"]\s*\)\s*\}\}/', $currentYear, $content);

        // {{ $current_year }} - ensure footer always shows actual year (fallback if not in $data)
        $content = str_replace('{{ $current_year }}', $currentYear, $content);
        $content = str_replace('{{$current_year}}', $currentYear, $content);

        // Literal "Sample Current year" (any casing) sometimes used in admin - replace with actual year
        $content = preg_replace('/Sample\s+Current\s+year/i', $currentYear, $content);

        return $content;
    }

    /**
     * Final pass on email body: ensure footer always shows actual year.
     * Replaces "Sample Current year" (any variant) and any leftover year placeholders.
     */
    private function ensureFooterYear(string $body): string
    {
        $year = (string) date('Y');

        // "Sample Current year" - any casing, spaces, or &nbsp; between words
        $body = preg_replace('/Sample[\s\xc2\xa0]+Current[\s\xc2\xa0]+year/i', $year, $body);
        $body = preg_replace('/Sample\s+Current\s+year/i', $year, $body);

        // Leftover placeholders that might still be in body
        $body = str_replace(['{{ $current_year }}', '{{$current_year}}'], $year, $body);
        $body = preg_replace('/\{\{\s*date\s*\(\s*[\'"]Y[\'"]\s*\)\s*\}\}/', $year, $body);

        return $body;
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
