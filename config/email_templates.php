<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deprecated Email Templates
    |--------------------------------------------------------------------------
    |
    | Templates listed here are no longer used by the application. They remain
    | in the database for reference but are hidden from the main admin list
    | and cannot be reactivated.
    |
    */
    'deprecated' => [
        'rentalJobOffer' => [
            'reason' => 'Legacy user-to-provider offer flow (route removed). Negotiation offers use jobOfferNotification instead.',
            'replaced_by' => 'jobOfferNotification',
        ],
    ],

];
