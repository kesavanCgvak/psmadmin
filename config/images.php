<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default fallback images
    |--------------------------------------------------------------------------
    |
    | Relative paths under public/ used when a company logo or user profile
    | image is missing or the file does not exist on disk.
    |
    */

    'default_company_logo' => env(
        'DEFAULT_COMPANY_LOGO',
        'images/company_images/default_company_logo.png'
    ),

    'default_profile_image' => env(
        'DEFAULT_PROFILE_IMAGE',
        'images/profile_pictures/default-profile-image.png'
    ),

];
