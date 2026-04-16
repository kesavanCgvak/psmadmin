<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportRequest;
use App\Models\IssueType;
use App\Models\ContactSales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class SupportRequestController extends Controller
{
    /**
     * Submit a support request
     */
    public function store(Request $request)
    {
        try {
            // Authenticate user via JWT
            $user = JWTAuth::parseToken()->authenticate();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|max:255',
                'description' => 'required|string',
                'issue_type_id' => 'required|exists:issue_types,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verify issue type is active
            $issueType = IssueType::findOrFail($request->issue_type_id);
            if (!$issueType->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected issue type is not available'
                ], 422);
            }

            // Get user information
            $profile = $user->profile;
            $company = $user->company;

            // Subject will be the issue type name
            $subject = $issueType->name;

            // Create support request
            $supportRequest = SupportRequest::create([
                'user_id' => $user->id,
                'issue_type_id' => $request->issue_type_id,
                'email' => $request->email,
                'subject' => $subject,
                'description' => $request->description,
                'status' => 'pending',
            ]);

            // Log the support request
            Log::info('Support request submitted', [
                'support_request_id' => $supportRequest->id,
                'user_id' => $user->id,
                'issue_type_id' => $request->issue_type_id,
                'email' => $request->email,
                'subject' => $subject,
            ]);

            // Prepare email data
            $emailData = [
                'company_name' => $company ? $company->name : 'N/A',
                'full_name' => $profile ? $profile->full_name : ($user->username ?? 'N/A'),
                'email' => $request->email,
                'telephone' => $profile ? ($profile->mobile ?? 'N/A') : 'N/A',
                'subject' => $subject,
                'description' => $request->description,
                'issue_type' => $issueType->name,
                'submitted_at' => $supportRequest->created_at->format('M d, Y h:i A'),
            ];

            // Get support inbox email from config or env
            $supportEmail = config('mail.support_inbox', env('SUPPORT_INBOX_EMAIL', config('mail.from.address')));

            // Send email to support inbox
            try {
                Log::info('Sending support request email', [
                    'support_email' => $supportEmail,
                    'subject' => $subject,
                    'email' => $request->email,
                    'name' => $profile->full_name,
                ]);

                \App\Helpers\EmailHelper::send('support-request', $emailData, function ($message) use ($supportEmail, $subject, $request, $profile) {
                    $message->to($supportEmail)
                        ->subject('New Support Request: ' . $subject)
                        ->from($request->email, $profile->full_name);
                });

                Log::info('Support request email sent', [
                    'support_request_id' => $supportRequest->id,
                    'support_email' => $supportEmail,
                ]);
            } catch (\Exception $e) {
                // Log email error but don't fail the request
                Log::error('Failed to send support request email', [
                    'support_request_id' => $supportRequest->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Support request submitted successfully',
                'data' => [
                    'id' => $supportRequest->id,
                    'status' => $supportRequest->status,
                    'created_at' => $supportRequest->created_at->toISOString(),
                ]
            ], 201);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Issue type not found'
            ], 404);

        } catch (\Throwable $e) {
            Log::error('Failed to submit support request', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to submit support request. Please try again later.'
            ], 500);
        }
    }

    /**
     * Contact sales - Submit a contact sales inquiry
     * No authentication required
     */
    public function contactSales(Request $request)
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone_number' => 'nullable|string|max:20',
                'description' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Log the contact sales inquiry
            Log::info('Contact sales inquiry submitted', [
                'name' => $request->name,
                'email' => $request->email,
                'phone_number' => $request->phone_number,
            ]);

            // Create contact sales record
            $contactSales = ContactSales::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone_number' => $request->phone_number,
                'description' => $request->description,
                'status' => 'pending',
            ]);

            // Prepare email data
            $emailData = [
                'name' => $request->name,
                'email' => $request->email,
                'phone_number' => $request->phone_number ?? 'Not provided',
                'description' => $request->description,
                'submitted_at' => now()->format('M d, Y h:i A'),
            ];

            // Send email to contact@secondwarehouse.com
            try {
                Log::info('Sending contact sales email', [
                    'contact_email' => 'contact@secondwarehouse.com',
                    'name' => $request->name,
                    'email' => $request->email,
                ]);

                // Get sales inbox email from config or env
            $salesEmail = config('mail.sales_inbox', env('SALES_INBOX_EMAIL', config('mail.from.address')));

                \App\Helpers\EmailHelper::send('contact-sales', $emailData, function ($message) use ($salesEmail, $request) {
                    $message->to($salesEmail)
                        ->subject('New Contact Sales Inquiry from ' . $request->name)
                        ->from($request->email, $request->name);
                });
            } catch (\Exception $e) {
                // Log email error but don't fail the request
                Log::error('Failed to send contact sales email', [
                    'contact_sales_id' => $contactSales->id,
                    'error' => $e->getMessage(),
                    'name' => $request->name,
                    'email' => $request->email,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Your inquiry has been submitted successfully. We will contact you soon.',
                'data' => [
                    'id' => $contactSales->id,
                    'submitted_at' => $contactSales->created_at->toISOString(),
                ]
            ], 201);

        } catch (\Throwable $e) {
            Log::error('Failed to submit contact sales inquiry', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to submit your inquiry. Please try again later.'
            ], 500);
        }
    }
}
