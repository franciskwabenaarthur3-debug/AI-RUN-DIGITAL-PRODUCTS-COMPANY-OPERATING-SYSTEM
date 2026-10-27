<?php

namespace App\Http\Controllers;

use App\Enums\CustomerStatus;
use App\Http\Requests\Customers\CustomerRequest;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Customer::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(CustomerStatus::class)],
        ]);

        return view('customers.index', [
            'customers' => Customer::query()
                ->search($filters['q'] ?? null)
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->orderBy('company_name')
                ->paginate(20)
                ->withQueryString(),
            'filters' => $filters,
            'statuses' => CustomerStatus::cases(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Customer::class);

        return view('customers.form', ['customer' => new Customer, 'statuses' => CustomerStatus::cases()]);
    }

    public function store(CustomerRequest $request, CustomerService $customers): RedirectResponse
    {
        $customer = $customers->create($request->validated(), $request->user());

        return redirect()->route('customers.show', $customer)->with('status', 'Customer created.');
    }

    public function show(Customer $customer): View
    {
        $this->authorize('view', $customer);

        return view('customers.show', ['customer' => $customer->load('lead')]);
    }

    public function edit(Customer $customer): View
    {
        $this->authorize('update', $customer);

        return view('customers.form', ['customer' => $customer, 'statuses' => CustomerStatus::cases()]);
    }

    public function update(CustomerRequest $request, Customer $customer, CustomerService $customers): RedirectResponse
    {
        $customers->update($customer, $request->validated(), $request->user());

        return redirect()->route('customers.show', $customer)->with('status', 'Customer updated.');
    }
}
