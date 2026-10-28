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
    | Two-step sign-in with an authenticator app. When required, a founder
    | without it can only reach the account security page until it is set up.
    */
    /*
    | Database backups (BackupService). Files go to a private folder outside
    | the web root; copy them off the server as well (see
    | docs/backup-recovery.md). The restore test needs an empty scratch
    | database that is not the live one.
    */
    'backups' => [
        'enabled' => (bool) env('BACKUP_ENABLED', true),
        'path' => env('BACKUP_PATH', storage_path('app/private/backups')),
        'pg_dump' => env('BACKUP_PG_DUMP', 'pg_dump'),
        'pg_restore' => env('BACKUP_PG_RESTORE', 'pg_restore'),
        'verify_database' => env('BACKUP_VERIFY_DATABASE'),
        'verify_host' => env('BACKUP_VERIFY_HOST'),
        'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
        'verify_max_age_days' => (int) env('BACKUP_VERIFY_MAX_AGE_DAYS', 8),
    ],

    /*
    | Monitoring: how long the scheduler and the queue worker may go unseen.
    */
    'monitoring' => [
        'scheduler_stale_minutes' => 12,
        'queue_stale_minutes' => 20,
        'queue_backlog_minutes' => 15,
    ],

    'security' => [
        'require_founder_two_factor' => (bool) env('SECURITY_REQUIRE_FOUNDER_TWO_FACTOR', true),
        'two_factor_issuer' => env('SECURITY_TWO_FACTOR_ISSUER', env('APP_NAME', 'Company OS')),
        'two_factor_max_attempts' => 5,
        'pending_login_minutes' => 5,
    ],

    /*
    | Metadata keys whose values are never written to audit records or logs.
    */
    'redacted_keys' => [
        'password', 'password_confirmation', 'current_password', 'token', 'secret',
        'api_key', 'apikey', 'authorization', 'cookie', 'remember_token', 'billing_details',
        'otp', 'recovery_code', 'two_factor_secret', 'two_factor_recovery_codes',
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
    | Project delivery.
    */
    'projects' => [
        // Used when a package has no delivery template of its own.
        'default_template' => [
            ['name' => 'Discovery', 'tasks' => ['Confirm requirements, pages and content with the client', 'Collect brand assets and account access']],
            ['name' => 'Design', 'tasks' => ['Prepare page designs', 'Get design feedback from the client']],
            ['name' => 'Build', 'tasks' => ['Build the site on staging', 'Add the client\'s content']],
            ['name' => 'Quality checks', 'tasks' => ['Run automated tests and the security scan', 'Check accessibility, speed and browsers']],
            ['name' => 'Launch and handover', 'tasks' => ['Release to production after approval', 'Hand over access, files and training']],
        ],
        'handover_checklist' => [
            'Final files and source delivered',
            'Admin access handed over securely (not by email)',
            'Client walkthrough or training done',
            'Backup taken and restore tested',
            'Maintenance plan offered',
        ],
        // Checks recorded on every release. Mandatory checks must pass
        // before a production release can be approved; no one can waive them.
        'release_checks' => [
            'automated_tests' => ['label' => 'Automated tests passed', 'mandatory' => true],
            'security_scan' => ['label' => 'Security scan with no high-severity findings', 'mandatory' => true],
            'content_review' => ['label' => 'Content and links reviewed', 'mandatory' => true],
            'backup' => ['label' => 'Backup of the current site taken', 'mandatory' => true],
            'accessibility' => ['label' => 'Accessibility check', 'mandatory' => false],
            'performance' => ['label' => 'Performance check', 'mandatory' => false],
            'cross_browser' => ['label' => 'Cross-browser and mobile check', 'mandatory' => false],
        ],
        'health_check_timeout_seconds' => (int) env('RELEASE_HEALTH_CHECK_TIMEOUT', 10),
        // Health checks never reach private or reserved addresses unless this is on (local testing only).
        'health_check_allow_private_hosts' => (bool) env('RELEASE_HEALTH_CHECK_ALLOW_PRIVATE', false),
    ],

    /*
    | Maintenance plans and visits.
    */
    'maintenance' => [
        // Checklist a new plan starts with. Every item is a non-destructive check.
        'default_checklist' => [
            'Confirm a recent backup exists and note where it is',
            'Check the site loads (health check)',
            'Check the SSL certificate expiry date',
            'Review available core, theme and plugin updates (do not apply on the live site without approval)',
            'Test the contact form and key pages',
            'Review uptime and error logs',
        ],
        // Visits are created this many days before they are due.
        'schedule_ahead_days' => (int) env('MAINTENANCE_SCHEDULE_AHEAD_DAYS', 7),
        // Renewal reminders start this many days before a plan's term ends.
        'renewal_reminder_days' => (int) env('MAINTENANCE_RENEWAL_REMINDER_DAYS', 30),
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
