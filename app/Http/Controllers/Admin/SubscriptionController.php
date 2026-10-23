<?php

namespace App\Http\Controllers\Admin;

use App\Models\Subscription;
use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            // The new admin: 25 per page, the newest first, one state at a time (?state=active); the members and
            // plans in one query each; the count per state in one query, kept for a minute.
            $states = AdminCache::remember('subscriptions.states.v1', now()->addMinute(), fn (): array => DB::table('premiums')
                ->selectRaw('stripe_status, COUNT(*) as total')
                ->groupBy('stripe_status')
                ->pluck('total', 'stripe_status')
                ->map(fn ($total): int => (int) $total)
                ->all());
            $state = array_key_exists((string) $request->query('state'), $states) ? (string) $request->query('state') : null;

            return view('admin.subscriptions.list', [
                'subscriptions' => Subscription::query()
                    ->with(['user:id,username,email', 'plan:id,name'])
                    ->when($state !== null, fn ($query) => $query->where('stripe_status', $state))
                    ->latest()
                    ->paginate(AdminPagination::perPage($request, 25))
                    ->withQueryString(),
                'states' => $states,
                'state' => $state,
            ]);
        }

        return view('admin.subscriptions.index', [
            'subscriptions' => Subscription::query()
                ->with(['user', 'plan'])
                ->latest()
                ->paginate(\App\Support\AdminPagination::perPage(null, 20))
                ->withQueryString()
        ]);
    }

    public function cancel(Subscription $subscription): RedirectResponse
    {
        $subscription->update([
            'stripe_status' => 'canceled',
            'ends_at' => now(),
        ]);

        return redirect()->route('admin.subscriptions.index')
            ->withSuccess('Subscription canceled successfully.');
    }

    public function activate(Subscription $subscription): RedirectResponse
    {
        $subscription->update([
            'stripe_status' => 'active',
            'ends_at' => null,
        ]);

        return redirect()->route('admin.subscriptions.index')
            ->withSuccess('Subscription activated successfully.');
    }

    public function destroy(Subscription $subscription): RedirectResponse
    {
        $subscription->delete();

        return redirect()->route('admin.subscriptions.index')
            ->withSuccess('Subscription deleted successfully.');
    }
}
