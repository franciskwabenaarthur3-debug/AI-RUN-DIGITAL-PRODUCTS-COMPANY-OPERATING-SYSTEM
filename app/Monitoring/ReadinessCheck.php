<?php

namespace App\Monitoring;

use App\Enums\UserRole;
use App\Models\Agent;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Whether this installation is configured safely enough for a pilot with
 * real clients. Each item passes, warns or fails; any failure means not
 * ready. Settings are read from config, so run it on the server itself.
 */
class ReadinessCheck
{
    public function __construct(private readonly SystemHealth $health) {}

    /**
     * @return list<array{label: string, status: string, detail: string}>
     */
    public function items(): array
    {
        $items = [];
        $add = function (string $label, bool|string $result, string $detail) use (&$items) {
            $items[] = ['label' => $label, 'status' => is_bool($result) ? ($result ? 'ok' : 'failed') : $result, 'detail' => $detail];
        };
        $production = app()->isProduction();

        $add('Production environment', $production ? 'ok' : 'warning', 'APP_ENV is '.app()->environment().($production ? '' : '; set APP_ENV=production on the live server'));
        $add('Debug mode off', ! config('app.debug'), config('app.debug') ? 'APP_DEBUG=true shows code and settings in error pages' : 'APP_DEBUG=false');
        $add('Application key set', filled(config('app.key')), 'Encrypts sessions, billing details and two-step secrets. Back it up with the database.');
        $add('HTTPS address', str_starts_with((string) config('app.url'), 'https://'), 'APP_URL is '.config('app.url'));
        $add('Secure session cookie', (bool) config('session.secure'), 'SESSION_SECURE_COOKIE='.(config('session.secure') ? 'true' : 'false'));
        $add('Encrypted sessions', (bool) config('session.encrypt'), 'SESSION_ENCRYPT');
        $add('PostgreSQL database', DB::connection()->getDriverName() === 'pgsql', 'Driver: '.DB::connection()->getDriverName());
        $add('Background queue', config('queue.default') !== 'sync', 'QUEUE_CONNECTION='.config('queue.default'));
        $add('Mock agent runtime off', ! config('company_os.agents.allow_mock_runtime') ? 'ok' : ($production ? 'failed' : 'warning'), 'AGENTS_ALLOW_MOCK_RUNTIME');
        $add('Health check cannot reach private addresses', ! config('company_os.projects.health_check_allow_private_hosts'), 'RELEASE_HEALTH_CHECK_ALLOW_PRIVATE');
        $add('Founder two-step sign-in required', (bool) config('company_os.security.require_founder_two_factor'), 'SECURITY_REQUIRE_FOUNDER_TWO_FACTOR');

        $founders = User::query()->where('role', UserRole::Founder)->where('is_active', true)->get();
        $add('An active founder account', $founders->isNotEmpty(), $founders->count().' active founder(s)');
        $without = $founders->reject->hasTwoFactor();
        $add('Founders use two-step sign-in', $without->isEmpty(), $without->isEmpty() ? 'All active founders' : 'Not yet: '.$without->pluck('email')->implode(', '));

        if (config('company_os.proposals.email_enabled')) {
            $mailer = config('company_os.proposals.mailer') ?: config('mail.default');
            $add('Proposal email goes to a real mail service', ! in_array(config("mail.mailers.{$mailer}.transport"), ['log', 'array', null], true), "Mailer: {$mailer}");
        }

        $llm = Agent::query()->where('status', 'enabled')->where('runtime', 'llm')->count();
        if ($llm > 0) {
            $add('AI provider key set', filled(config('services.anthropic.key')), "{$llm} enabled agent(s) use a real model");
            $add('Monthly AI budget set', CompanySetting::current()->monthly_ai_budget_minor > 0, 'Paid runs are refused while the budget is 0');
        }

        foreach ($this->health->checks() as $check) {
            if (in_array($check['key'], ['scheduler', 'queue', 'backup', 'restore'], true)) {
                $add($check['label'].' working', $check['status'], $check['detail']);
            }
        }

        return $items;
    }

    public function ready(): bool
    {
        return ! in_array('failed', array_column($this->items(), 'status'), true);
    }

    /**
     * Who can do what, for the permissions review before going live.
     *
     * @return array{users: Collection, agents: Collection}
     */
    public function permissions(): array
    {
        return [
            'users' => User::query()->orderBy('role')->orderBy('email')->get(['id', 'name', 'email', 'role', 'is_active', 'two_factor_confirmed_at', 'last_login_at']),
            'agents' => Agent::query()->with(['permissions' => fn ($q) => $q->where('allowed', true)])->orderBy('name')->get(),
        ];
    }
}
