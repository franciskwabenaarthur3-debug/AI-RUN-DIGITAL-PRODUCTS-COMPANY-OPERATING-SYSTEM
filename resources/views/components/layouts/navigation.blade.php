<nav aria-label="Main" class="space-y-1">
    <x-nav-link route="dashboard">Dashboard</x-nav-link>
    <x-nav-link route="settings.edit">Settings</x-nav-link>
    @can('viewAny', App\Models\AuditLog::class)
        <x-nav-link route="audit.index">Audit log</x-nav-link>
    @endcan
</nav>
