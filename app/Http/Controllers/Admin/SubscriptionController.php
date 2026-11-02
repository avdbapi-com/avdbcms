<?php

namespace App\Http\Controllers\Admin;

use App\Support\AuditDetails;
use App\Models\Subscription;
use App\Services\SubscriptionCanceller;
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

    public function cancel(Subscription $subscription, SubscriptionCanceller $canceller): RedirectResponse
    {
        // Stripe / PayPal first, so the member is not charged again (the site's own change is made either way).
        $confirmed = $canceller->cancelAtProvider($subscription, 'Cancelled by the site administrator');

        $subscription->update([
            'stripe_status' => 'canceled',
            'ends_at' => now(),
        ]);
        // Tools > Logs > Admin actions (R3-019): whose subscription it is.
        AuditLogController::log('subscription.cancelled', self::memberOf($subscription));

        return $this->done($confirmed, 'Subscription canceled successfully.');
    }

    public function activate(Subscription $subscription): RedirectResponse
    {
        $wasActive = $subscription->stripe_status === 'active' && $subscription->ends_at === null;
        $subscription->update([
            'stripe_status' => 'active',
            'ends_at' => null,
        ]);

        if (! $wasActive) {
            AuditLogController::log('subscription.activated', self::memberOf($subscription));
        }

        return redirect()->route('admin.subscriptions.index')
            ->withSuccess(__('Subscription active again'));
    }

    public function destroy(Subscription $subscription, SubscriptionCanceller $canceller): RedirectResponse
    {
        $confirmed = $canceller->cancelAtProvider($subscription, 'Cancelled by the site administrator');

        $name = self::memberOf($subscription);
        $subscription->delete();
        AuditLogController::log('subscription.deleted', $name);

        return $this->done($confirmed, 'Subscription deleted successfully.');
    }

    /** "alice (#12)": the member a subscription belongs to, or its number when the member is gone. */
    private static function memberOf(Subscription $subscription): string
    {
        $member = $subscription->user_id !== null ? DB::table('users')->where('id', $subscription->user_id)->first(['id', 'username']) : null;

        return $member !== null ? AuditDetails::member($member) : '#'.$subscription->getKey();
    }

    /** Back to the list with the message, plus a warning when Stripe or PayPal did not confirm the cancellation. */
    private function done(bool $confirmed, string $message): RedirectResponse
    {
        $redirect = redirect()->route('admin.subscriptions.index')->withSuccess($message);

        return $confirmed ? $redirect : $redirect->withErrors([
            'payment_provider' => __('The payment provider did not confirm the cancellation. Cancel this subscription in the Stripe or PayPal dashboard too.'),
        ]);
    }
}
