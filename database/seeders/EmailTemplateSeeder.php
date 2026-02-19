<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\EmailTemplate;
use Illuminate\Support\Facades\File;

class EmailTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'name' => 'registrationSuccess',
                'subject' => 'Welcome to ProSub Marketplace - Account Created Successfully',
                'description' => 'Email sent to users when their account is successfully created',
                'variables' => ['name', 'email', 'username', 'password', 'account_type', 'login_url'],
                'file' => 'registrationSuccess.blade.php',
            ],
            [
                'name' => 'forgotPassword',
                'subject' => 'Password Reset - Pro Subrental Marketplace',
                'description' => 'Email sent when user requests password reset',
                'variables' => ['full_name', 'email', 'token', 'reset_url'],
                'file' => 'forgotPassword.blade.php',
            ],
            [
                'name' => 'verificationEmail',
                'subject' => 'Email Verification - ProSub Marketplace',
                'description' => 'Email sent to verify user email address',
                'variables' => ['token', 'username'],
                'file' => 'verificationEmail.blade.php',
            ],
            [
                'name' => 'jobCompletionReminder',
                'subject' => 'Reminder: Please complete this job',
                'description' => 'Reminder email sent to suppliers to complete jobs',
                'variables' => ['rental_job_name', 'unpack_date', 'reminder_label', 'days_since_unpack'],
                'file' => 'jobCompletionReminder.blade.php',
            ],
            [
                'name' => 'jobRatingReminder',
                'subject' => 'Reminder: Please rate this job',
                'description' => 'Reminder email sent to renters to rate completed jobs',
                'variables' => ['rental_job_name', 'supply_job_name', 'provider_company_name', 'days_since_completion'],
                'file' => 'jobRatingReminder.blade.php',
            ],
            [
                'name' => 'jobRatingRequest',
                'subject' => 'Rate Your Experience',
                'description' => 'Email sent to request job rating from renter',
                'variables' => ['rental_job_name', 'supply_job_name', 'provider_company_name', 'rating_url'],
                'file' => 'jobRatingRequest.blade.php',
            ],
            [
                'name' => 'rentalJobOffer',
                'subject' => 'New Offer Received from Pro Subrental Marketplace',
                'description' => 'Email sent when a new offer is received for a rental job',
                'variables' => ['user_name', 'job_name', 'amount', 'currency_symbol', 'sent_at'],
                'file' => 'rentalJobOffer.blade.php',
            ],
            [
                'name' => 'supplyNewOffer',
                'subject' => 'New Supply Offer Received',
                'description' => 'Email sent when a new supply offer is received',
                'variables' => ['user_name', 'job_name', 'amount', 'currency_symbol', 'sent_at'],
                'file' => 'supplyNewOffer.blade.php',
            ],
            [
                'name' => 'rentalJobCancelled',
                'subject' => 'Rental Job Cancelled',
                'description' => 'Email sent when a rental job is cancelled',
                'variables' => ['job_name', 'cancellation_reason', 'cancelled_at'],
                'file' => 'rentalJobCancelled.blade.php',
            ],
            [
                'name' => 'supplyJobCancelled',
                'subject' => 'Supply Job Cancelled',
                'description' => 'Email sent when a supply job is cancelled',
                'variables' => ['job_name', 'cancellation_reason', 'cancelled_at'],
                'file' => 'supplyJobCancelled.blade.php',
            ],
            [
                'name' => 'jobHandshakeAccepted',
                'subject' => 'Job Handshake Accepted',
                'description' => 'Email sent when a job handshake is accepted',
                'variables' => ['job_name', 'company_name', 'accepted_at'],
                'file' => 'jobHandshakeAccepted.blade.php',
            ],
            [
                'name' => 'subscriptionCreated',
                'subject' => 'Subscription Created',
                'description' => 'Email sent when a subscription is created',
                'variables' => ['company_name', 'subscription_plan', 'amount', 'billing_date'],
                'file' => 'subscriptionCreated.blade.php',
            ],
            [
                'name' => 'subscriptionCanceled',
                'subject' => 'Subscription Cancelled',
                'description' => 'Email sent when a subscription is cancelled',
                'variables' => ['company_name', 'subscription_plan', 'cancelled_at'],
                'file' => 'subscriptionCanceled.blade.php',
            ],
            [
                'name' => 'support-request',
                'subject' => 'New Support Request',
                'description' => 'Email sent to support team when a new support request is submitted',
                'variables' => ['company_name', 'full_name', 'email', 'telephone', 'issue_type', 'subject', 'description', 'submitted_at'],
                'file' => 'support-request.blade.php',
            ],
            [
                'name' => 'contact-sales',
                'subject' => 'New Contact Sales Inquiry',
                'description' => 'Email sent to sales team when a contact sales form is submitted',
                'variables' => ['name', 'email', 'phone_number', 'description', 'submitted_at'],
                'file' => 'contact-sales.blade.php',
            ],
            [
                'name' => 'new-admin-user',
                'subject' => 'Welcome to PSM Admin Panel',
                'description' => 'Email sent to new admin users when account is created',
                'variables' => ['user', 'password', 'isPasswordReset', 'adminPanelUrl'],
                'file' => 'new-admin-user.blade.php',
            ],
        ];

        foreach ($templates as $templateData) {
            $filePath = resource_path('views/emails/' . $templateData['file']);
            
            if (File::exists($filePath)) {
                $body = File::get($filePath);
                
                // Create or update template
                EmailTemplate::updateOrCreate(
                    ['name' => $templateData['name']],
                    [
                        'subject' => $templateData['subject'],
                        'body' => $body,
                        'variables' => $templateData['variables'],
                        'description' => $templateData['description'],
                        'is_active' => true,
                    ]
                );
                
                $this->command->info("Migrated template: {$templateData['name']}");
            } else {
                $this->command->warn("Template file not found: {$templateData['file']}");
            }
        }

        $this->command->info('Email templates migration completed!');
    }
}
