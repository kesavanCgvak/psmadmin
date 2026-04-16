<?php

namespace App\Helpers;

use App\Services\EmailTemplateService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EmailHelper
{
    /**
     * Send email using template from database or fallback to blade file.
     * If template is disabled in database, email will NOT be sent.
     *
     * @param string $templateName Template identifier (e.g., 'registrationSuccess')
     * @param array $data Data to pass to template
     * @param callable $callback Callback function to configure message (to, from, etc.)
     * @return bool Returns true if email sent, false if skipped (disabled) or failed
     */
    public static function send(string $templateName, array $data, callable $callback): bool
    {
        try {
            $emailService = new EmailTemplateService();
            
            // Check if template exists in database and is disabled
            $templateInDb = \App\Models\EmailTemplate::where('name', $templateName)->first();
            if ($templateInDb && !$templateInDb->is_active) {
                // Template is disabled - do not send email
                Log::info('Email not sent: template is disabled', [
                    'template' => $templateName,
                ]);
                return false; // Return false to indicate email was skipped
            }
            
            $template = $emailService->getTemplate($templateName, $data);

            if ($template) {
                // Use template from database or blade file
                Mail::send([], [], function ($message) use ($template, $callback) {
                    // Set subject and body from template
                    $message->subject($template['subject']);
                    $message->html($template['body']);
                    
                    // Apply callback for to, from, etc.
                    $callback($message);
                });

                return true;
            } else {
                // Template not found - fallback to blade file only if template doesn't exist in DB
                if (!$templateInDb) {
                    Log::warning('Email template not found in database, using blade file directly', [
                        'template' => $templateName,
                    ]);

                    // Try original method as fallback
                    Mail::send("emails.{$templateName}", $data, $callback);
                    return true;
                } else {
                    // Template exists but is disabled (already handled above, but double-check)
                    Log::info('Email not sent: template is disabled', [
                        'template' => $templateName,
                    ]);
                    return false;
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to send email', [
                'template' => $templateName,
                'error' => $e->getMessage(),
            ]);

            // Only fallback if template doesn't exist in DB
            $templateInDb = \App\Models\EmailTemplate::where('name', $templateName)->first();
            if (!$templateInDb) {
                try {
                    Mail::send("emails.{$templateName}", $data, $callback);
                    return true;
                } catch (\Exception $fallbackError) {
                    Log::error('Fallback email sending also failed', [
                        'template' => $templateName,
                        'error' => $fallbackError->getMessage(),
                    ]);
                    return false;
                }
            }
            
            return false;
        }
    }
}
