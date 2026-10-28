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

    /*
    | AI gateway. Prices are in minor units of `currency` per million tokens,
    | taken from the provider's published price list on 2026-10-06; check
    | them against current pricing before relying on cost figures. Costs
    | recorded from them are estimates, never actual charges.
    */
    'ai' => [
        // Providers bill in USD, so AI budgets and costs are kept in USD.
        'currency' => 'USD',
        'request_timeout_seconds' => (int) env('AI_REQUEST_TIMEOUT_SECONDS', 120),
        // Retries of one model call for transient errors (429, 5xx, network).
        'max_retries' => (int) env('AI_MAX_RETRIES', 2),
        // Per-run cap on provider-hosted web searches, and their price per 1,000.
        'web_search_max_uses' => (int) env('AI_WEB_SEARCH_MAX_USES', 3),
        'web_search_minor_per_1000' => 1000,
        'models' => [
            'claude-opus-5-5' => [
                'label' => 'Claude Opus 5.5',
                'provider' => 'anthropic',
                'model' => 'claude-opus-5-5',
                'version' => '2026-10-28',
                'input_minor_per_mtok' => 400,
                'output_minor_per_mtok' => 2000,
                'max_output_tokens' => 16000,
                'effort' => 'medium',
                'web_search' => true,
            ],
            'claude-sonnet-5-5' => [
                'label' => 'Claude Sonnet 5.5',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-5-5',
                'version' => '2026-10-28',
                'input_minor_per_mtok' => 200,
                'output_minor_per_mtok' => 1000,
                'max_output_tokens' => 16000,
                'effort' => 'medium',
                'web_search' => true,
            ],
            'claude-haiku-5-5' => [
                'label' => 'Claude Haiku 5.5',
                'provider' => 'anthropic',
                'model' => 'claude-haiku-5-5',
                'version' => '2026-10-28',
                'input_minor_per_mtok' => 10,
                'output_minor_per_mtok' => 50,
                'max_output_tokens' => 16000,
                'effort' => 'medium',
                // Not enabled: support for the current web search tool on this model is unconfirmed.
                'web_search' => false,
            ],
        ],
    ],

    /*
    | Proposals. Sending by email stays off until a real mailer is
    | configured; until then a founder records sends made outside the app.
    */
    'proposals' => [
        'email_enabled' => (bool) env('PROPOSALS_EMAIL_ENABLED', false),
        // Laravel mailer used for proposals; null uses MAIL_MAILER.
        'mailer' => env('PROPOSALS_MAILER'),
        'max_discount_percent' => (int) env('PROPOSALS_MAX_DISCOUNT_PERCENT', 20),
        'max_items' => 30,
        'default_valid_days' => 30,
        'default_payment_terms' => '50% deposit before work starts, 50% on delivery. Invoices are due within 14 days.',
    ],

    /*
    | Approvals.
    */
    'approvals' => [
        // How long a request stays open for a decision, and how long an
        // approval may wait before execution.
        'ttl_hours' => (int) env('APPROVALS_TTL_HOURS', 72),
        // An execution still running after this long is flagged for manual
        // reconciliation rather than retried.
        'reconcile_after_minutes' => (int) env('APPROVALS_RECONCILE_AFTER_MINUTES', 30),
    ],

];
