<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class EmailTemplateController extends Controller
{
    /**
     * Display a listing of the email templates.
     */
    public function index()
    {
        $templates = EmailTemplate::orderBy('name')->get();
        return view('admin.email-templates.index', compact('templates'));
    }

    /**
     * Show the form for editing the specified email template.
     * Body is run through placeholder replacement so {{ asset('images/logo-white.png') }} etc.
     * display correctly in the visual editor (no broken image request).
     */
    public function edit(EmailTemplate $emailTemplate)
    {
        $emailService = new EmailTemplateService();
        $bodyForEdit = $emailService->replacePlaceholdersInContent($emailTemplate->body);
        return view('admin.email-templates.edit', compact('emailTemplate', 'bodyForEdit'));
    }

    /**
     * Update the specified email template in storage.
     */
    public function update(Request $request, EmailTemplate $emailTemplate)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            'variables' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $emailTemplate->update([
                'subject' => $request->input('subject'),
                'body' => $request->input('body'),
                'description' => $request->input('description'),
                'is_active' => $request->has('is_active') ? true : false,
                'variables' => $request->input('variables') ?: [],
            ]);

            return redirect()->route('admin.email-templates.index')
                ->with('success', 'Email template updated successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to update email template', [
                'template_id' => $emailTemplate->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to update email template: ' . $e->getMessage());
        }
    }

    /**
     * Toggle active status of the email template.
     */
    public function toggleStatus(EmailTemplate $emailTemplate)
    {
        try {
            $emailTemplate->update([
                'is_active' => !$emailTemplate->is_active,
            ]);

            $status = $emailTemplate->is_active ? 'activated' : 'deactivated';
            
            return redirect()->route('admin.email-templates.index')
                ->with('success', "Email template {$status} successfully.");
        } catch (\Exception $e) {
            Log::error('Failed to toggle email template status', [
                'template_id' => $emailTemplate->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->with('error', 'Failed to toggle template status: ' . $e->getMessage());
        }
    }

    /**
     * Preview email template with sample data.
     */
    public function preview(EmailTemplate $emailTemplate)
    {
        // Generate sample data based on template variables
        $variables = $emailTemplate->variables ?? [];

        // Ensure key variables exist for specific templates even if variables list is outdated
        if ($emailTemplate->name === 'new-admin-user') {
            $variables = array_unique(array_merge($variables, [
                'heading',
                'greeting_name',
                'body_message',
                'role_line',
                'adminPanelUrl',
                'user_email',
                'role_display',
                'capabilities_section',
                'current_year',
            ]));
        } elseif ($emailTemplate->name === 'jobAutoCancelled') {
            // jobAutoCancelled template expects these, even if DB variables list is older
            $variables = array_unique(array_merge($variables, [
                'receiver_contact_name',
                'rental_job_name',
                'fulfilled_quantity',
                'date',
                'current_year',
            ]));
        }

        $sampleData = $this->generateSampleData($variables);
        
        // Replace variables in subject and body (same variants as EmailTemplateService)
        $subject = $emailTemplate->subject;
        $body = $emailTemplate->body;
        foreach ($sampleData as $key => $value) {
            $replacements = [
                '{{' . $key . '}}' => $value,
                '{{ $' . $key . ' }}' => $value,
                '{{$' . $key . '}}' => $value,
                '{{ $' . $key . '}}' => $value,
                '{{$' . $key . ' }}' => $value,
                '{!! $' . $key . ' !!}' => $value,
                '{!!$' . $key . '!!}' => $value,
            ];
            foreach ($replacements as $placeholder => $replacement) {
                $subject = str_replace($placeholder, $replacement, $subject);
                $body = str_replace($placeholder, $replacement, $body);
            }
        }

        // Replace env/config/asset placeholders (e.g. {{ asset('images/logo-white.png') }}) so preview doesn't request them as URLs
        $emailService = new EmailTemplateService();
        $subject = $emailService->replacePlaceholdersInContent($subject);
        $body = $emailService->replacePlaceholdersInContent($body);

        return view('admin.email-templates.preview', [
            'subject' => $subject,
            'body' => $body,
            'template' => $emailTemplate,
        ]);
    }

    /**
     * Generate sample data for preview based on variable names.
     */
    private function generateSampleData(array $variables): array
    {
        $sampleData = [];
        
        foreach ($variables as $variable) {
            $varName = is_array($variable) ? ($variable['name'] ?? '') : $variable;
            
            // Generate sample data based on variable name patterns
            if (stripos($varName, 'product_name') !== false) {
                $sampleData[$varName] = 'Canon EOS R5 Camera';
            } elseif (stripos($varName, 'product_brand') !== false) {
                $sampleData[$varName] = 'Canon';
            } elseif (stripos($varName, 'product_category') !== false && stripos($varName, 'sub_category') === false) {
                $sampleData[$varName] = 'Cameras';
            } elseif (stripos($varName, 'product_sub_category') !== false) {
                $sampleData[$varName] = 'DSLR';
            } elseif (stripos($varName, 'product_psm_code') !== false) {
                $sampleData[$varName] = 'PSM-001234';
            } elseif (stripos($varName, 'company_name') !== false) {
                $sampleData[$varName] = 'Acme Rentals';
            } elseif (stripos($varName, 'name') !== false) {
                $sampleData[$varName] = 'John Doe';
            } elseif (stripos($varName, 'email') !== false) {
                $sampleData[$varName] = 'john.doe@example.com';
            } elseif (stripos($varName, 'username') !== false) {
                $sampleData[$varName] = 'johndoe';
            } elseif (stripos($varName, 'password') !== false) {
                $sampleData[$varName] = '********';
            } elseif (stripos($varName, 'token') !== false) {
                $sampleData[$varName] = 'abc123xyz789';
            } elseif (stripos($varName, 'webpage_url') !== false) {
                $sampleData[$varName] = 'https://example.com/product';
            } elseif (stripos($varName, 'url') !== false || stripos($varName, 'link') !== false) {
                $sampleData[$varName] = 'https://example.com/action';
            } elseif (stripos($varName, 'current_year') !== false) {
                $sampleData[$varName] = (string) date('Y');
            } elseif (stripos($varName, 'from_date') !== false || stripos($varName, 'to_date') !== false) {
                // Rental dates in quoteRequest emails should show date only
                $sampleData[$varName] = date('d M Y');
            } elseif (stripos($varName, 'date') !== false) {
                // Other generic date fields (e.g. jobNegotiationCancelled "date") include time
                $sampleData[$varName] = date('d M Y, h:i A');
            } elseif (stripos($varName, 'amount') !== false || stripos($varName, 'price') !== false) {
                // Amounts are numeric; currency symbol comes from currency_symbol
                $sampleData[$varName] = '1,000.00';
            } elseif (stripos($varName, 'currency_symbol') !== false) {
                $sampleData[$varName] = '$';
            } elseif (stripos($varName, 'provider_contact_name') !== false) {
                $sampleData[$varName] = 'Jane Smith';
            } elseif (stripos($varName, 'rental_job_name') !== false || stripos($varName, 'rental_name') !== false) {
                $sampleData[$varName] = 'Sample Rental Job';
            } elseif (stripos($varName, 'remaining_quantity') !== false) {
                $sampleData[$varName] = '4';
            } elseif (stripos($varName, 'products_count') !== false) {
                $sampleData[$varName] = '3';
            } elseif (stripos($varName, 'products_table_html') !== false) {
                // For imported_products: show Imported Products table
                // For quoteRequest: this still looks reasonable as a generic equipment table
                $sampleData[$varName] =
                    '<h3 style="margin-top: 25px; color: #1a73e8;">Imported Products (3)</h3>'
                    . '<table width="100%" cellpadding="8" cellspacing="0" style="border: 1px solid #ccc; border-radius: 6px; margin-top: 10px; font-size: 14px;">'
                    . '<thead style="background-color: #e8eef8;">'
                    . '<tr>'
                    . '<th align="left">Model</th>'
                    . '<th align="left">Brand</th>'
                    . '<th align="left">Category</th>'
                    . '<th align="left">PSM Code</th>'
                    . '<th align="left">Rental Software Code</th>'
                    . '</tr>'
                    . '</thead>'
                    . '<tbody>'
                    . '<tr><td>Sharpy Wash 330</td><td>Clay Paky</td><td>Moving Lights</td><td>PSM08137</td><td>TC7827</td></tr>'
                    . '<tr><td>VL3500 Spot</td><td>Vari-Lite</td><td>Moving Lights</td><td>PSM09210</td><td>VL3500</td></tr>'
                    . '<tr><td>GrandMA3 Full Size</td><td>MA Lighting</td><td>Lighting Console</td><td>PSM10001</td><td>GMA3FS</td></tr>'
                    . '</tbody></table>';
            } elseif (stripos($varName, 'from_date') !== false || stripos($varName, 'to_date') !== false) {
                $sampleData[$varName] = date('M d, Y');
            } elseif (stripos($varName, 'delivery_address') !== false) {
                $sampleData[$varName] = '123 Main St, City, State';
            } elseif (stripos($varName, 'user_company') !== false) {
                $sampleData[$varName] = 'Acme Rentals';
            } elseif (stripos($varName, 'user_mobile') !== false) {
                $sampleData[$varName] = '+1 234 567 8900';
            } elseif (stripos($varName, 'region_name') !== false) {
                $sampleData[$varName] = 'West';
            } elseif (stripos($varName, 'country_name') !== false) {
                $sampleData[$varName] = 'United States';
            } elseif (stripos($varName, 'state_name') !== false) {
                $sampleData[$varName] = 'California';
            } elseif (stripos($varName, 'city_name') !== false) {
                $sampleData[$varName] = 'Los Angeles';
            } elseif (stripos($varName, 'mobile') !== false) {
                $sampleData[$varName] = '+1 234 567 8900';
            } elseif (stripos($varName, 'account_type') !== false) {
                $sampleData[$varName] = 'Provider';
            } elseif (stripos($varName, 'global_message_section') !== false) {
                $sampleData[$varName] = '<h3 style="color: #1a73e8;">Global Message</h3><p style="background: #f9f9f9; padding: 12px; border-left: 4px solid #1a73e8;">Sample global message for this request.</p>';
            } elseif (stripos($varName, 'offer_requirements_section') !== false) {
                $sampleData[$varName] = '<h3 style="color: #1a73e8;">Offer Requirements</h3><p>Sample offer requirements.</p>';
            } elseif (stripos($varName, 'private_message_section') !== false) {
                $sampleData[$varName] = '<h3 style="color: #1a73e8;">Private Message</h3><p style="background: #f9f9f9; padding: 12px; border-left: 4px solid #1a73e8;">Sample private message to supplier.</p>';
            } elseif (stripos($varName, 'initial_offer_section') !== false) {
                $sampleData[$varName] = '<h3 style="color: #1a73e8;">Initial Offer Negotiation</h3><p><b>Offer Price : </b>$90.00</p>';
            } elseif (stripos($varName, 'similar_request_note') !== false) {
                $sampleData[$varName] = '<p style="margin-top: 15px; padding: 12px; background-color: #e8f4fd; border-left: 4px solid #1a73e8; font-size: 14px; line-height: 1.5;"><strong>Note:</strong> The requester is also open to similar or equivalent products. Please contact the requester if you can offer suitable alternatives.</p>';
            } elseif (stripos($varName, 'products_section') !== false && stripos($varName, 'products_table') === false) {
                // Sample for jobNegotiationCancelled / jobPartialFulfilled (Product Details table)
                // Match the actual runtime structure in JobNegotiationController (Qty / Price / Total)
                $sampleData[$varName] = '<h3 style="color:#1a73e8; margin-top: 30px;">Product Details</h3>'
                    . '<table width="100%" cellpadding="8" cellspacing="0" style="border-collapse: collapse;">'
                    . '<thead><tr style="background:#e8f0fe;">'
                    . '<th style="border-bottom:1px solid #ccc;">PSM Code</th>'
                    . '<th style="border-bottom:1px solid #ccc;">Model</th>'
                    . '<th style="border-bottom:1px solid #ccc;">Software Code</th>'
                    . '<th style="border-bottom:1px solid #ccc;">Qty</th>'
                    . '<th style="border-bottom:1px solid #ccc;">Price</th>'
                    . '<th style="border-bottom:1px solid #ccc;">Total</th>'
                    . '</tr></thead>'
                    . '<tbody>'
                    . '<tr>'
                    . '<td>PSM19563</td>'
                    . '<td>AMORAN</td>'
                    . '<td>PSM00022</td>'
                    . '<td>10</td>'
                    . '<td>$40.00</td>'
                    . '<td>$400.00</td>'
                    . '</tr>'
                    . '</tbody></table>';
            } elseif (stripos($varName, 'reason') !== false) {
                $sampleData[$varName] = 'No longer needed.';
            } elseif (stripos($varName, 'total_price') !== false && stripos($varName, 'product') === false) {
                $sampleData[$varName] = '₹10,500.00';
            } else {
                $sampleData[$varName] = 'Sample ' . ucfirst(str_replace('_', ' ', $varName));
            }
        }
        
        return $sampleData;
    }
}
