<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PaymentSettingsController extends Controller
{
    /**
     * Display payment settings
     */
    public function index()
    {
        $paymentEnabled = Setting::isPaymentEnabled();
        
        return view('admin.payment-settings.index', compact('paymentEnabled'));
    }

    /**
     * Update payment settings
     */
    public function update(Request $request)
    {
        try {
            // Checkbox sends "1" when checked, nothing when unchecked
            // So we check if the value is present and equals "1"
            $enabled = $request->has('payment_enabled') && $request->input('payment_enabled') == '1';

            if ($enabled) {
                Setting::enablePayment();
                $message = 'Payment requirement has been enabled. All new registrations will require credit card.';
            } else {
                Setting::disablePayment();
                $message = 'Payment requirement has been disabled. Users can register without credit card.';
            }

            // Clear all setting caches to ensure fresh data
            Cache::flush();

            // 302 redirect is NORMAL - it redirects back to the page with success message
            return redirect()
                ->route('admin.payment-settings.index')
                ->with('success', $message);
        } catch (\Exception $e) {
            \Log::error('Failed to update payment settings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Failed to update payment settings: ' . $e->getMessage());
        }
    }

    /**
     * Toggle payment status (AJAX endpoint)
     */
    public function toggle(Request $request)
    {
        $currentStatus = Setting::isPaymentEnabled();
        $newStatus = !$currentStatus;

        if ($newStatus) {
            Setting::enablePayment();
        } else {
            Setting::disablePayment();
        }

        Cache::flush();

        return response()->json([
            'success' => true,
            'enabled' => $newStatus,
            'message' => $newStatus 
                ? 'Payment requirement enabled' 
                : 'Payment requirement disabled',
        ]);
    }
}

