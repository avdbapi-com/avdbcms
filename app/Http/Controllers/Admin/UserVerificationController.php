<?php

namespace App\Http\Controllers\Admin;

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
        User::query()->whereKey($id)->update(['email_verified_at' => now()]);

        return redirect()->back()->withSuccess('User verified.');
    }

    public function unverify(int $id): RedirectResponse
    {
        User::query()->whereKey($id)->update(['email_verified_at' => null]);

        return redirect()->back()->withSuccess('User unverified.');
    }

    public function verifyAll(): RedirectResponse
    {
        $count = User::query()
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);

        return redirect()->route('admin.verification.index')->withSuccess("{$count} users verified.");
    }
}
