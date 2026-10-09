<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\StripeSubscriptionService;
use Illuminate\Support\Facades\Log;

class PaymentStatusController extends Controller
{
    /**
     * Get payment status (for frontend)
     */
    public function status()
    {
        $enabled = Setting::isPaymentEnabled();
        
        return response()->json([
            'success' => true,
            'payment_enabled' => $enabled,
            'message' => $enabled 
                ? 'Payment is required for registration' 
                : 'Payment is not required for registration',
        ]);
    }

    /**
     * Publishable Stripe key for the customer app. The secret key stays on the server.
     */
    public function publicKey()
    {
        $key = (string) config('services.stripe.key');

        if ($key === '' || !str_starts_with($key, 'pk_')) {
            return response()->json([
                'success' => false,
                'message' => 'Stripe publishable key is not configured.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'public_key' => $key,
        ]);
    }

    /**
     * Create a SetupIntent for registration, before the visitor has an account.
     */
    public function setupIntent(StripeSubscriptionService $subscriptionService)
    {
        if (!Setting::isPaymentEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Payment is currently disabled.',
                'payment_enabled' => false,
            ], 403);
        }

        try {
            $setupIntent = $subscriptionService->createSetupIntent();

            return response()->json([
                'success' => true,
                'client_secret' => $setupIntent->client_secret,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create registration SetupIntent', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not start payment setup. Please try again.',
            ], 500);
        }
    }
}


