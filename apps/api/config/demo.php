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

    /*
    | Optional full key (pb_<prefix>_<secret>) of an external system that delivers
    | to the demo organization, such as the PulseBoard POS. The command keeps it as
    | a managed key: never expires, cannot be revoked through the API, and is
    | restored on every run.
    */

    'integration_key' => env('DEMO_INTEGRATION_KEY'),

    'integration_key_name' => env('DEMO_INTEGRATION_KEY_NAME', 'PulseBoard POS'),

];
