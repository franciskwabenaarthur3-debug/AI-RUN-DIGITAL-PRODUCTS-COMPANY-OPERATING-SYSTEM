<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServicePackages\ServicePackageRequest;
use App\Models\CompanySetting;
use App\Models\ServicePackage;
use App\Services\ServicePackageService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ServicePackageController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', ServicePackage::class);

        return view('service-packages.index', [
            'packages' => ServicePackage::query()->orderByDesc('is_active')->orderBy('name')->paginate(20),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', ServicePackage::class);

        $package = new ServicePackage(['currency' => CompanySetting::current()->default_currency, 'is_active' => true, 'revision_limit' => 2]);

        return view('service-packages.form', ['package' => $package, 'price' => '', 'currencies' => config('company_os.currencies')]);
    }

    public function store(ServicePackageRequest $request, ServicePackageService $packages): RedirectResponse
    {
        $packages->create($request->validated(), $request->user());

        return redirect()->route('service-packages.index')->with('status', 'Service package created.');
    }

    public function edit(ServicePackage $servicePackage): View
    {
        $this->authorize('update', $servicePackage);

        return view('service-packages.form', [
            'package' => $servicePackage,
            'price' => Money::fromMinor($servicePackage->base_price_minor),
            'currencies' => config('company_os.currencies'),
        ]);
    }

    public function update(ServicePackageRequest $request, ServicePackage $servicePackage, ServicePackageService $packages): RedirectResponse
    {
        $packages->update($servicePackage, $request->validated(), $request->user());

        return redirect()->route('service-packages.index')->with('status', 'Service package updated.');
    }
}
