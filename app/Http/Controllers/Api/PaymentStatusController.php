<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

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
}


