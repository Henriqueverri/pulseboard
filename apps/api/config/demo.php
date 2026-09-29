<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo organization (php artisan pulseboard:demo)
    |--------------------------------------------------------------------------
    |
    | Public demo accounts for the portfolio deployment. There is no default
    | password: the command refuses to run without DEMO_PASSWORD.
    |
    */

    'password' => env('DEMO_PASSWORD'),

    'owner_email' => env('DEMO_OWNER_EMAIL', 'demo@example.com'),

    'member_email' => env('DEMO_MEMBER_EMAIL', 'demo-member@example.com'),

];
