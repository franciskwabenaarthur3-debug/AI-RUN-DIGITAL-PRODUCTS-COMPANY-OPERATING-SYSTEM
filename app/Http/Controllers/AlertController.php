<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Services\AlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AlertController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Alert::class);
        $showAll = $request->boolean('all');

        return view('alerts.index', [
            'alerts' => Alert::query()->when(! $showAll, fn ($q) => $q->open())->with('acknowledgedBy:id,name')
                ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")->latest('id')->paginate(30)->withQueryString(),
            'showAll' => $showAll,
        ]);
    }

    public function acknowledge(Request $request, Alert $alert, AlertService $alerts): RedirectResponse
    {
        $this->authorize('acknowledge', $alert);
        $alerts->acknowledge($alert, $request->user());

        return back()->with('status', 'Alert acknowledged.');
    }
}
