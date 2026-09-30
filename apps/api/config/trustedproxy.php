<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Proxies whose X-Forwarded-* headers are trusted: "*" (the calling proxy,
    | for hosts where the app is only reachable through the platform's proxy,
    | such as Render) or a comma-separated list of IPs/CIDRs. Empty trusts none.
    | Without it, behind a proxy every client shares the proxy IP (login rate
    | limit) and requests are seen as plain HTTP.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
