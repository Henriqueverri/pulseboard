<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Push notifications (mobile app)
    |--------------------------------------------------------------------------
    |
    | Alerts (low stock, daily revenue goal) are sent to the Expo Push API after
    | the ingestion response, inside the same defer() as the realtime broadcast.
    | No queue worker is needed.
    |
    | The optional access token (enhanced push security in the EAS dashboard)
    | lives in config/services.php (EXPO_ACCESS_TOKEN), never here.
    |
    */

    // expo | fake (records the messages in memory and logs them: tests and local development)
    'driver' => env('PUSH_DRIVER', 'expo'),

    'timeout_seconds' => (int) env('PUSH_TIMEOUT_SECONDS', 10),

    'connect_timeout_seconds' => (int) env('PUSH_CONNECT_TIMEOUT_SECONDS', 5),

    // Android notification channel created by the app before it asks for permission.
    'android_channel_id' => 'alerts',

];
