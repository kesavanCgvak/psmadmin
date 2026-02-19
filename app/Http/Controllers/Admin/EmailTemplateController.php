<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
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
     */
    public function edit(EmailTemplate $emailTemplate)
    {
        return view('admin.email-templates.edit', compact('emailTemplate'));
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
        $sampleData = $this->generateSampleData($emailTemplate->variables ?? []);
        
        // Replace variables in subject
        $subject = $emailTemplate->subject;
        foreach ($sampleData as $key => $value) {
            $subject = str_replace('{{' . $key . '}}', $value, $subject);
            $subject = str_replace('{{ $' . $key . ' }}', $value, $subject);
        }

        // Replace variables in body
        $body = $emailTemplate->body;
        foreach ($sampleData as $key => $value) {
            $body = str_replace('{{' . $key . '}}', $value, $body);
            $body = str_replace('{{ $' . $key . ' }}', $value, $body);
        }

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
            if (stripos($varName, 'name') !== false) {
                $sampleData[$varName] = 'John Doe';
            } elseif (stripos($varName, 'email') !== false) {
                $sampleData[$varName] = 'john.doe@example.com';
            } elseif (stripos($varName, 'username') !== false) {
                $sampleData[$varName] = 'johndoe';
            } elseif (stripos($varName, 'password') !== false) {
                $sampleData[$varName] = '********';
            } elseif (stripos($varName, 'token') !== false) {
                $sampleData[$varName] = 'abc123xyz789';
            } elseif (stripos($varName, 'url') !== false || stripos($varName, 'link') !== false) {
                $sampleData[$varName] = 'https://example.com/action';
            } elseif (stripos($varName, 'date') !== false) {
                $sampleData[$varName] = date('M d, Y');
            } elseif (stripos($varName, 'amount') !== false || stripos($varName, 'price') !== false) {
                $sampleData[$varName] = '$1,000.00';
            } else {
                $sampleData[$varName] = 'Sample ' . ucfirst(str_replace('_', ' ', $varName));
            }
        }
        
        return $sampleData;
    }
}
