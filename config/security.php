<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Enabled outside local development by default (the Vite dev server needs
    | its own origin). Scripts require a per-request nonce; styles allow inline
    | because UI libraries inject runtime styles.
    |
    */

    'csp_enabled' => (bool) env('SECURITY_CSP_ENABLED', env('APP_ENV') !== 'local'),

    /*
    | Origin browsers use for Reverb WebSockets (CSP connect-src). Defaults to the
    | public Reverb host; set it when the app publishes to an internal Reverb host.
    */

    'websocket_origin' => env('SECURITY_WS_ORIGIN'),

    'hsts' => (bool) env('SECURITY_HSTS', env('APP_ENV') === 'production'),

];
