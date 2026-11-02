<?php

namespace App\Http\Controllers\Admin;

use App\Support\AuditDetails;
use App\Models\Subscription;
use App\Services\SubscriptionCanceller;
use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PremiumController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            // The new admin: 25 per page, the newest first; the three counts in one query, kept for a minute.
            return view('admin.premiums.list', [
                'premiums' => DB::table('premiums')
                    ->leftJoin('users', 'premiums.user_id', '=', 'users.id')
                    ->leftJoin('plans', 'premiums.plan_id', '=', 'plans.id')
                    ->select('premiums.id', 'premiums.user_id', 'premiums.payment_provider', 'premiums.stripe_status', 'premiums.ends_at', 'premiums.created_at', 'users.username', 'users.email as user_email', 'plans.name as plan_name')
                    ->orderByDesc('premiums.created_at')
                    ->paginate(AdminPagination::perPage($request, 25))
                    ->withQueryString(),
                'stats' => AdminCache::remember('premiums.counts.v1', now()->addMinute(), function (): array {
                    $row = DB::table('premiums')->selectRaw("
                        COUNT(*) as total,
                        SUM(CASE WHEN stripe_status IN ('active', 'trialing') AND (ends_at IS NULL OR ends_at > ?) THEN 1 ELSE 0 END) as active,
                        SUM(CASE WHEN ends_at <= ? THEN 1 ELSE 0 END) as expired
                    ", [now(), now()])->first();

                    return ['total' => (int) ($row->total ?? 0), 'active' => (int) ($row->active ?? 0), 'expired' => (int) ($row->expired ?? 0)];
                }),
            ]);
        }

        $activeQuery = fn ($query) => $query
            ->whereIn('stripe_status', ['active', 'trialing'])
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            });

        $premiums = DB::table('premiums')
            ->leftJoin('users', 'premiums.user_id', '=', 'users.id')
            ->leftJoin('plans', 'premiums.plan_id', '=', 'plans.id')
            ->select('premiums.*', 'users.username', 'users.email as user_email', 'plans.name as plan_name')
            ->orderByDesc('premiums.created_at')
            ->paginate(\App\Support\AdminPagination::perPage(null, 30));

        $stats = [
            'total' => DB::table('premiums')->count(),
            'active' => $activeQuery(DB::table('premiums'))->count(),
            'expired' => DB::table('premiums')->where('ends_at', '<=', now())->count(),
        ];

        return view('admin.premiums.index', compact('premiums', 'stats'));
    }

    public function destroy(int $id, SubscriptionCanceller $canceller): RedirectResponse
    {
        // Stripe / PayPal first, so the member is not charged again (the record goes either way).
        $premium = Subscription::query()->find($id);
        $confirmed = $premium === null || $canceller->cancelAtProvider($premium, 'Cancelled by the site administrator');

        $name = self::memberOf($id);

        if (DB::table('premiums')->where('id', $id)->delete() > 0) {
            // Tools > Logs > Admin actions (R3-019): whose premium record it was.
            AuditLogController::log('premium.deleted', $name);
        }

        $redirect = redirect()->route('admin.premiums.index')->withSuccess(__('Premium record deleted'));

        return $confirmed ? $redirect : $redirect->withErrors([
            'payment_provider' => __('The payment provider did not confirm the cancellation. Cancel this subscription in the Stripe or PayPal dashboard too.'),
        ]);
    }

    public function extend(Request $request, int $id): RedirectResponse
    {
        $request->validate(['days' => 'required|integer|min:1|max:365']);
        $days = (int) $request->days;

        $premium = DB::table('premiums')->where('id', $id)->first();

        if (!$premium) {
            return redirect()->route('admin.premiums.index')->withSuccess(__('Premium record not found.'));
        }

        $baseDate = $premium->ends_at
            ? Carbon::parse($premium->ends_at)
            : now();

        if ($baseDate->isPast()) {
            $baseDate = now();
        }

        DB::table('premiums')->where('id', $id)->update([
            'ends_at' => $baseDate->copy()->addDays($days),
            'updated_at' => now(),
        ]);
        AuditLogController::log('premium.extended', self::memberOf($id).', +'.$days.' '.($days === 1 ? 'day' : 'days'));

        return redirect()->route('admin.premiums.index')->withSuccess(trans_choice('Added 1 day.|Added :count days.', $days));
    }

    /** "alice (#12)": the member of a premium record, or the record's number when it has none. */
    private static function memberOf(int $id): string
    {
        $member = DB::table('premiums')->join('users', 'premiums.user_id', '=', 'users.id')->where('premiums.id', $id)->first(['users.id', 'users.username']);

        return $member !== null ? AuditDetails::member($member) : '#'.$id;
    }
}
