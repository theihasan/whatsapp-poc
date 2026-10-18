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

    'hsts' => (bool) env('SECURITY_HSTS', env('APP_ENV') === 'production'),

];
