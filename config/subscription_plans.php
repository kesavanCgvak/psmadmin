<?php

return [
    'provider' => [
        'default' => [
            'name' => 'Provider Plan',
            'stripe_price_id' => env('STRIPE_PRICE_PROVIDER_PLAN', 'price_xxx'), // Your Stripe Provider Plan Price ID
            'amount' => 99.00,
            'currency' => 'USD',
            'interval' => 'month',
            'trial_days' => 60, // 60 days free trial
            'requires_payment_method' => true, // Credit card required on registration
        ],
    ],
    'user' => [
        'default' => [
            'name' => 'User Plan',
            'stripe_price_id' => env('STRIPE_PRICE_USER_PLAN', 'price_xxx'), // Your Stripe User Plan Price ID
            'amount' => 2.99,
            'currency' => 'USD',
            'interval' => 'month',
            'trial_days' => 14, // 14 days free trial
            'requires_payment_method' => true, // Credit card required on registration (same as providers)
        ],
    ],
];


