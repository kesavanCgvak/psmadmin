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
        
        // Load company and subscription relationships
        $user->load(['company.subscription', 'subscription']);
        
        // Get effective subscription (company subscription for provider companies, individual for regular users)
        $subscription = $user->getEffectiveSubscription();
        
        if (!$subscription) {
            // Determine message based on account type
            $message = 'No subscription found';
            if ($user->company && $user->company->account_type === 'provider') {
                $message = 'Your company subscription has expired or is inactive. Please renew to continue.';
            }
            
            return response()->json([
                'success' => false,
                'message' => $message,
                'requires_subscription' => true
            ], 403);
        }
        
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
            $message = 'Subscription payment required. Please update your payment method.';
            if ($user->company && $user->company->account_type === 'provider') {
                $message = 'Your company subscription payment failed. Please update payment method to continue.';
            }
            
            return response()->json([
                'success' => false,
                'message' => $message,
                'payment_required' => true,
                'subscription_status' => 'unpaid',
            ], 402);
        }
        
        // Other inactive statuses
        $message = 'Active subscription required';
        if ($user->company && $user->company->account_type === 'provider') {
            $message = 'Your company subscription has expired or is inactive. Please renew to continue.';
        }
        
        return response()->json([
            'success' => false,
            'message' => $message,
            'subscription_status' => $subscription->stripe_status,
        ], 403);
    }
}


