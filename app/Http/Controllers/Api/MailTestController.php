<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class MailTestController extends Controller
{
    /**
     * Test email endpoint - For testing and debugging mail configuration only
     * 
     * WARNING: This endpoint is for testing purposes only. Consider restricting
     * access in production environments.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testEmail(Request $request)
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $testEmail = $request->email;
            $subject = 'Test Email - Mail Configuration Test';
            $sentAt = now()->format('M d, Y h:i A');
            
            // Log the test email attempt
            Log::info('Test email attempt', [
                'test_email' => $testEmail,
                'subject' => $subject,
                'sent_at' => $sentAt,
            ]);

            // Prepare email data
            $emailData = [
                'test_email' => $testEmail,
                'sent_at' => $sentAt,
                'mail_config' => [
                    'driver' => config('mail.default'),
                    'from_address' => config('mail.from.address'),
                    'from_name' => config('mail.from.name'),
                ],
            ];

            // Send test email
            try {
                Mail::send('emails.test-email', $emailData, function ($message) use ($testEmail, $subject) {
                    $message->to($testEmail)
                        ->subject($subject)
                        ->from(config('mail.from.address'), config('mail.from.name'));
                });

                Log::info('Test email sent successfully', [
                    'test_email' => $testEmail,
                    'subject' => $subject,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Test email sent successfully',
                    'data' => [
                        'to' => $testEmail,
                        'subject' => $subject,
                        'sent_at' => $sentAt,
                        'mail_config' => $emailData['mail_config'],
                    ]
                ], 200);

            } catch (\Swift_TransportException $e) {
                // Legacy Swift Mailer: SMTP connection errors
                Log::error('Test email failed - SMTP connection error', [
                    'test_email' => $testEmail,
                    'error' => $e->getMessage(),
                    'error_code' => $e->getCode(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send test email: SMTP connection error',
                    'error' => $e->getMessage(),
                    'error_type' => 'SMTP Connection Error',
                    'hint' => 'Please check your SMTP settings in .env file (MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD)',
                ], 500);

            } catch (\Swift_RfcComplianceException $e) {
                // Legacy Swift Mailer: Invalid email address format
                Log::error('Test email failed - Invalid email address', [
                    'test_email' => $testEmail,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send test email: Invalid email address format',
                    'error' => $e->getMessage(),
                    'error_type' => 'Invalid Email Address',
                ], 400);

            } catch (\Exception $e) {
                // Other email errors
                Log::error('Test email failed - General error', [
                    'test_email' => $testEmail,
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send test email',
                    'error' => $e->getMessage(),
                    'error_type' => get_class($e),
                    'hint' => 'Please check your mail configuration and ensure all required settings are properly configured.',
                ], 500);
            }

        } catch (\Throwable $e) {
            Log::error('Test email endpoint error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while processing the test email request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
