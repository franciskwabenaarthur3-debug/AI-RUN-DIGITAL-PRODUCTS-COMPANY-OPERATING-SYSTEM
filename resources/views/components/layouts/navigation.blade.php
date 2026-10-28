<nav aria-label="Main" class="space-y-1">
    <x-nav-link route="dashboard">Dashboard</x-nav-link>
    <x-nav-link route="leads.index" active="leads.*">Leads</x-nav-link>
    <x-nav-link route="customers.index" active="customers.*">Customers</x-nav-link>
    <x-nav-link route="proposals.index" active="proposals.*">Proposals</x-nav-link>
    <x-nav-link route="projects.index" active="projects.*,releases.*">Projects</x-nav-link>
    <x-nav-link route="maintenance.index" active="maintenance.*">Maintenance</x-nav-link>
    <x-nav-link route="service-packages.index" active="service-packages.*">Service packages</x-nav-link>
    <x-nav-link route="tasks.index" active="tasks.*">Tasks</x-nav-link>
    <x-nav-link route="agents.index" active="agents.*">Agents</x-nav-link>
    <x-nav-link route="approvals.index" active="approvals.*">Approvals</x-nav-link>
    @php($openAlerts = App\Models\Alert::query()->open()->count())
    <x-nav-link route="alerts.index" active="alerts.*">Alerts @if ($openAlerts)<span class="ml-1 rounded-full bg-amber-500 px-1.5 text-xs font-semibold text-charcoal-950">{{ $openAlerts }}<span class="sr-only"> open</span></span>@endif</x-nav-link>
    @can('viewAny', App\Models\OperatingReport::class)
        <x-nav-link route="reports.index" active="reports.*">Reports</x-nav-link>
    @endcan
    <x-nav-link route="settings.edit">Settings</x-nav-link>
    @can('viewAny', App\Models\AuditLog::class)
        <x-nav-link route="audit.index">Audit log</x-nav-link>
    @endcan
</nav>
