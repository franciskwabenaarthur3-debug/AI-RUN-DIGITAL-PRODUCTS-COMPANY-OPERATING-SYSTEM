<?php

namespace App\Console\Commands;

use App\Monitoring\ReadinessCheck;
use Illuminate\Console\Command;

class ReadinessReport extends Command
{
    protected $signature = 'company-os:readiness';

    protected $description = 'Check whether this installation is ready for a pilot with real clients, and list who can do what';

    public function handle(ReadinessCheck $readiness): int
    {
        $items = $readiness->items();
        $this->table(['', 'Check', 'Detail'], array_map(fn ($i) => [strtoupper($i['status']), $i['label'], $i['detail']], $items));

        $permissions = $readiness->permissions();
        $this->line('People:');
        $this->table(['Email', 'Role', 'Active', 'Two-step', 'Last sign-in'], $permissions['users']->map(fn ($u) => [$u->email, $u->role->value, $u->is_active ? 'yes' : 'no', $u->two_factor_confirmed_at ? 'on' : 'off', $u->last_login_at?->toDateTimeString() ?? 'never'])->all());
        $this->line('Agents:');
        $this->table(['Agent', 'Status', 'Runtime', 'Granted tools'], $permissions['agents']->map(fn ($a) => [$a->name, $a->status->value, $a->runtime, $a->permissions->pluck('tool_name')->implode(', ') ?: 'none'])->all());

        if (! $readiness->ready()) {
            $this->error('Not ready: fix the FAILED items above.');

            return self::FAILURE;
        }
        $this->info('No failed checks. Review the people and agent permissions above before going live.');

        return self::SUCCESS;
    }
}
