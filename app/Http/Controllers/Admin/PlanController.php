<?php

namespace App\Http\Controllers\Admin;
use App\Models\Plan;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PlanController
{
    public function index (Request $request) : View {
        $planQuery = Plan::query()->latest();
        $billingPlans = (clone $planQuery)->get();
        $plans = $planQuery->paginate(\App\Support\AdminPagination::perPage(null, 25));
        $newAdmin = AdminPreferences::usesNewAdmin($request->user());

        return view($newAdmin ? 'admin.plans.list' : 'admin.plans.index', [
            'plans' => $newAdmin ? $plans->withQueryString() : $plans,
            'billingStatus' => [
                'premium_enabled' => config('site.premium_enabled', true),
                'stripe_ready' => config('site.stripe_enabled', true)
                    && filled(config('cashier.key'))
                    && filled(config('cashier.secret'))
                    && filled(config('cashier.webhook.secret')),
                'paypal_ready' => config('site.paypal_enabled', true)
                    && filled(config('services.paypal.client_id'))
                    && filled(config('services.paypal.client_secret'))
                    && filled(config('services.paypal.webhook_id')),
                'stripe_missing_plans' => $billingPlans->filter(fn (Plan $plan) => blank($plan->stripe_id))->count(),
                'paypal_missing_plans' => $billingPlans->filter(fn (Plan $plan) => blank($plan->paypal_plan_id))->count(),
                'stripe_webhook_url' => route('stripe_webhook'),
                'paypal_webhook_url' => route('paypal_webhook'),
            ],
        ]);
    }

    public function create () : View {
        return AdminPreferences::usesNewAdmin()
            ? view('admin.plans.record', ['plan' => new Plan()])
            : view('admin.plans.create');
    }

    public function store (Request $request) : RedirectResponse {

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0'],
            'duration' => ['required', 'integer', 'in:1,12'],
            'stripe_id' => ['nullable', 'string', 'max:255'],
            'paypal_plan_id' => ['nullable', 'string', 'max:255', 'regex:/^P-[A-Z0-9]+$/i'],
        ]);

        Plan::create($validated);

        return redirect()
            ->route('admin.plans.index')
            ->withSuccess('Plan created successfully.');
    }

    public function edit (Plan $plan) : View {
        return view(AdminPreferences::usesNewAdmin() ? 'admin.plans.record' : 'admin.plans.edit', compact('plan'));
    }

    public function update (Plan $plan, Request $request) : RedirectResponse {

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0'],
            'duration' => ['required', 'integer', 'in:1,12'],
            'stripe_id' => ['nullable', 'string', 'max:255'],
            'paypal_plan_id' => ['nullable', 'string', 'max:255', 'regex:/^P-[A-Z0-9]+$/i'],
        ]);

        $plan->update($validated);

        return redirect()
            ->route('admin.plans.index')
            ->withSuccess('Plan updated successfully.');
    }

    public function destroy (Plan $plan) : RedirectResponse {

        $plan->delete();

        return redirect()
            ->route('admin.plans.index')
            ->withSuccess('Plan deleted successfully.');
    }
}
