<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rental request shipping methods
    |--------------------------------------------------------------------------
    |
    | Keys are stored on rental_jobs.shipping_method. Labels are shown in the
    | admin panel, emails, and API option lists.
    |
    */
    'shipping_methods' => [
        'pickup' => 'I will pick it up',
        'deliver_to_me' => 'You deliver to me',
        'ship_to_job_site' => 'You ship to Job Site',
    ],

    'default_shipping_method' => 'deliver_to_me',

];
