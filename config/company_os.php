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

    /*
    | Agent execution.
    */
    'agents' => [
        // The mock runtime performs no real work. Keep it off in production.
        'allow_mock_runtime' => (bool) env('AGENTS_ALLOW_MOCK_RUNTIME', in_array(env('APP_ENV'), ['local', 'testing'], true)),
        // Base delay between automatic retries, doubled each attempt.
        'retry_backoff_seconds' => (int) env('AGENTS_RETRY_BACKOFF_SECONDS', 30),
        // Extra time past a run's deadline before it is treated as lost.
        'stale_run_grace_seconds' => (int) env('AGENTS_STALE_RUN_GRACE_SECONDS', 60),
    ],

];
