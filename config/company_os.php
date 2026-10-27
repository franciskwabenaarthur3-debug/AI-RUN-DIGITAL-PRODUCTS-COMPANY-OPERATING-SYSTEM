<?php

return [

    /*
    | Currencies the founder may choose as the company default. All of them
    | use two decimal places, which App\Support\Money relies on.
    */
    'currencies' => ['USD', 'EUR', 'GBP', 'GHS', 'NGN', 'KES', 'ZAR', 'CAD', 'AUD'],

    /*
    | Login throttling: attempts allowed per email + IP before lockout.
    */
    'login_max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),

    /*
    | Metadata keys whose values are never written to audit records or logs.
    */
    'redacted_keys' => [
        'password', 'password_confirmation', 'current_password', 'token', 'secret',
        'api_key', 'apikey', 'authorization', 'cookie', 'remember_token', 'billing_details',
    ],

];
