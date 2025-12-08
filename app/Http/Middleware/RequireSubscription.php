<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSubscription
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
            ], 401);
        }
        
        if (!$user->subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No subscription found',
                'requires_subscription' => true
            ], 403);
        }
        
        $subscription = $user->subscription;
        
        // Allow access if active or trialing
        if ($subscription->isActive() || $subscription->isOnTrial()) {
            return $next($request);
        }
        
        // Handle payment failure cases
        if ($subscription->isPastDue()) {
            // Grace period - allow access but show warning
            return response()->json([
                'success' => false,
                'message' => 'Payment failed. Please update your payment method to continue.',
                'payment_required' => true,
                'subscription_status' => 'past_due',
            ], 402); // 402 Payment Required
        }
        
        if ($subscription->isUnpaid()) {
            // Payment failed, restrict access
            return response()->json([
                'success' => false,
                'message' => 'Subscription payment required. Please update your payment method.',
                'payment_required' => true,
                'subscription_status' => 'unpaid',
            ], 402);
        }
        
        // Other inactive statuses
        return response()->json([
            'success' => false,
            'message' => 'Active subscription required',
            'subscription_status' => $subscription->stripe_status,
        ], 403);
    }
}


