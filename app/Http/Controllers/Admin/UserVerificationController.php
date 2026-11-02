<?php

namespace App\Http\Controllers\Admin;

use App\Support\AuditDetails;
use App\Models\User;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserVerificationController
{
    public function index(Request $request): View
    {
        $query = User::query();

        // The new admin opens it as the "Email not confirmed" tab of the members: on those members.
        $newAdmin = AdminPreferences::usesNewAdmin($request->user());
        $filter = $request->get('filter', $newAdmin ? 'unverified' : 'all');
        if ($filter === 'verified') {
            $query->whereNotNull('email_verified_at');
        } elseif ($filter === 'unverified') {
            $query->whereNull('email_verified_at');
        }

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->select('id', 'username', 'email', 'email_verified_at', 'is_admin', 'created_at')
            ->orderByDesc('created_at')
            ->paginate(AdminPagination::perPage($request, $newAdmin ? 25 : 50))
            ->withQueryString();

        $stats = [
            'total' => User::query()->count(),
            'verified' => User::query()->whereNotNull('email_verified_at')->count(),
            'unverified' => User::query()->whereNull('email_verified_at')->count(),
        ];

        return view($newAdmin ? 'admin.verification.list' : 'admin.verification.index', compact('users', 'stats', 'filter'));
    }

    public function verify(int $id): RedirectResponse
    {
        // Tools > Logs > Admin actions (R3-019): as Members > Confirm email; a confirmed address changes nothing.
        if (User::query()->whereKey($id)->whereNull('email_verified_at')->update(['email_verified_at' => now()]) > 0) {
            AuditLogController::log('member.email_confirmed', AuditDetails::member(User::query()->findOrFail($id)));
        }

        return redirect()->back()->withSuccess(__('Member verified'));
    }

    public function unverify(int $id): RedirectResponse
    {
        if (User::query()->whereKey($id)->whereNotNull('email_verified_at')->update(['email_verified_at' => null]) > 0) {
            AuditLogController::log('member.email_unconfirmed', AuditDetails::member(User::query()->findOrFail($id)));
        }

        return redirect()->back()->withSuccess(__('Member no longer verified'));
    }

    public function verifyAll(): RedirectResponse
    {
        $first = User::query()->whereNull('email_verified_at')->orderBy('id')->limit(AuditDetails::LIST_MAX)->get(['id', 'username']);
        $count = User::query()
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);

        if ($count > 0) {
            AuditLogController::log($count === 1 ? 'member.email_confirmed' : 'members.email_confirmed', AuditDetails::names($first->map(fn (User $user): string => AuditDetails::member($user)), $count));
        }

        return redirect()->route('admin.verification.index')->withSuccess(trans_choice('Marked 1 email as confirmed.|Marked :count emails as confirmed.', $count));
    }
}
