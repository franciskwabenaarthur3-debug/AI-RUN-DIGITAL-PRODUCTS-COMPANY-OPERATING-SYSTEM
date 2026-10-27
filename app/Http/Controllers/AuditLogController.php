<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', AuditLog::class);

        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
        ]);

        $logs = AuditLog::query()
            ->when($validated['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $actors = User::query()
            ->whereIn('id', $logs->getCollection()->where('actor_type', 'user')->pluck('actor_id')->filter()->unique())
            ->pluck('name', 'id');

        return view('audit.index', [
            'logs' => $logs,
            'actors' => $actors,
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'selectedAction' => $validated['action'] ?? null,
        ]);
    }
}
