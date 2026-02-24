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
                'variables' => ['name', 'email', 'username', 'password', 'account_type', 'login_url', 'current_year'],
                'file' => 'registrationSuccess.blade.php',
            ],
            [
                'name' => 'forgotPassword',
                'subject' => 'Password Reset - Pro Subrental Marketplace',
                'description' => 'Email sent when user requests password reset',
                'variables' => ['full_name', 'email', 'token', 'reset_url', 'current_year'],
                'file' => 'forgotPassword.blade.php',
            ],
            [
                'name' => 'verificationEmail',
                'subject' => 'Email Verification - ProSub Marketplace',
                'description' => 'Email sent to verify user email address',
                'variables' => ['token', 'username', 'verify_url', 'current_year'],
                'file' => 'verificationEmail.blade.php',
            ],
            [
                'name' => 'jobCompletionReminder',
                'subject' => 'Reminder: Please complete this job',
                'description' => 'Reminder email sent to suppliers to complete jobs',
                'variables' => ['rental_job_name', 'unpack_date', 'reminder_label', 'days_since_unpack', 'current_year'],
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
                'variables' => ['user_name', 'job_name', 'amount', 'currency_symbol', 'sent_at', 'current_year'],
                'file' => 'rentalJobOffer.blade.php',
            ],
            [
                'name' => 'supplyNewOffer',
                'subject' => 'New Supply Offer Received',
                'description' => 'Email sent when a new supply offer is received',
                'variables' => ['provider_name', 'rental_job_name', 'amount', 'currency_symbol', 'sent_at', 'current_year'],
                'file' => 'supplyNewOffer.blade.php',
            ],
            [
                'name' => 'rentalJobCancelled',
                'subject' => 'Rental Job Cancelled',
                'description' => 'Email sent when a rental job is cancelled',
                'variables' => ['receiver_contact_name', 'requester_company_name', 'rental_job_name', 'status', 'reason', 'date', 'products_section', 'current_year'],
                'file' => 'rentalJobCancelled.blade.php',
            ],
            [
                'name' => 'supplyJobCancelled',
                'subject' => 'Supply Job Cancelled',
                'description' => 'Email sent when a supply job is cancelled',
                'variables' => ['provider', 'supply_job_name', 'status', 'reason_display', 'date', 'products_section', 'current_year'],
                'file' => 'supplyJobCancelled.blade.php',
            ],
            [
                'name' => 'jobHandshakeAccepted',
                'subject' => 'Job Handshake Accepted',
                'description' => 'Email sent when a job handshake is accepted',
                'variables' => ['rental_job_name', 'sender', 'receiver', 'amount', 'currency_symbol', 'date', 'unpacking_date', 'products_html', 'current_year'],
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
                'variables' => ['username', 'heading', 'cancellation_message', 'plan_name', 'status', 'billing_line', 'service_continues_until', 'important_notice', 'app_url', 'current_year'],
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
            [
                'name' => 'product_created',
                'subject' => 'New Product Added to Marketplace',
                'description' => 'Email sent to admin when a new product is added via create-or-attach',
                'variables' => ['user_full_name', 'user_email', 'company_name', 'product_name', 'product_brand', 'product_category', 'product_sub_category', 'product_psm_code', 'webpage_url', 'current_year'],
                'file' => 'product_created.blade.php',
            ],
            [
                'name' => 'imported_products',
                'subject' => 'Product Import Completed - New Items Added',
                'description' => 'Email sent to admin when products are imported',
                'variables' => ['user_full_name', 'user_email', 'company_name', 'products_count', 'products_table_html', 'current_year'],
                'file' => 'imported_products.blade.php',
            ],
            [
                'name' => 'newRegistration',
                'subject' => 'New Registration - Pro Subrental Marketplace',
                'description' => 'Email sent to admin when a new company/user registers',
                'variables' => ['company_name', 'account_type', 'username', 'region_name', 'country_name', 'city_name', 'state_name', 'mobile', 'email', 'current_year'],
                'file' => 'newRegistration.blade.php',
            ],
            [
                'name' => 'quoteRequest',
                'subject' => 'New Quote Request - Pro Subrental Marketplace',
                'description' => 'Email sent to supplier when a quote/rental request is received',
                'variables' => ['rental_name', 'from_date', 'to_date', 'delivery_address', 'provider_contact_name', 'user_name', 'user_email', 'user_mobile', 'user_company', 'currency_symbol', 'global_message_section', 'offer_requirements_section', 'private_message_section', 'initial_offer_section', 'products_table_html', 'similar_request_note', 'current_year'],
                'file' => 'quoteRequest.blade.php',
            ],
            [
                'name' => 'test-email',
                'subject' => 'PSM Test Email',
                'description' => 'Test email for mail configuration',
                'variables' => ['test_email', 'sent_at', 'mail_config', 'current_year'],
                'file' => 'test-email.blade.php',
            ],
            [
                'name' => 'jobAutoCancelled',
                'subject' => 'Job Auto-Cancelled',
                'description' => 'Email sent to supplier when a job is auto-cancelled (e.g. handshake completed by another)',
                'variables' => ['rental_job_name', 'receiver', 'current_year'],
                'file' => 'jobAutoCancelled.blade.php',
            ],
            [
                'name' => 'jobPartialFulfilled',
                'subject' => 'Job Partially Fulfilled',
                'description' => 'Email sent when a job is partially fulfilled',
                'variables' => ['rental_job_name', 'remaining_quantity', 'date', 'products_section', 'current_year'],
                'file' => 'jobPartialFulfilled.blade.php',
            ],
            [
                'name' => 'jobNegotiationCancelled',
                'subject' => 'Job Negotiation Cancelled',
                'description' => 'Email sent when a job negotiation/offer is cancelled',
                'variables' => ['sender', 'receiver', 'rental_job_name', 'date', 'reason', 'total_price', 'currency_symbol', 'products_section', 'current_year'],
                'file' => 'jobNegotiationCancelled.blade.php',
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
