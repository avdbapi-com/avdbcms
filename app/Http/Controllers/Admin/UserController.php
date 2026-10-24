<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VideoStatus;
use App\Events\UserBanned;
use App\Exports\UsersExport;
use App\Http\Middleware\ViewOnlyDemo;
use App\Models\User;
use App\Notifications\Account\DeleteAccount;
use App\Services\ExportService;
use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController
{
    public function index (Request $request) : View {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.users.list', $this->listPage($request));
        }

        $perPage = AdminPagination::perPage($request, 15);
        $page = max(1, (int) $request->query('page', 1));
        $requestFingerprint = AdminCache::fingerprintQuery($request->query());
        $list = fn () => User::filter($request->query())
            ->with('premium_subscription')
            ->withCount([
                'subscribers',
                'videos',
                'comments',
                'interactions'
            ])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        // A view-only demo account searches without the e-mail column: its list is never shared through the cache.
        $users = ViewOnlyDemo::hidesEmails()
            ? $list()
            : AdminCache::remember("admin.users.index.v1.{$perPage}.p{$page}.{$requestFingerprint}", now()->addMinutes(1), $list);

        return view('admin.users.index', [
            'users' => $users
        ]);
    }

    /**
     * The new admin's list: the columns it shows with their counts in the same query, the premium plan eager loaded,
     * 25 rows by default, the tab counts cached for a minute (any admin change clears them).
     */
    private function listPage(Request $request): array
    {
        return [
            'users' => User::filter($request->query())
                ->with('premium_subscription')
                ->withCount(['videos', 'comments'])
                ->orderBy('created_at', 'desc')
                ->paginate(AdminPagination::perPage($request, 25))
                ->withQueryString(),
            'counts' => AdminCache::remember('users.tabs.v1', now()->addMinute(), fn (): array => [
                'all' => User::query()->count(),
                'banned' => User::filter(['status' => 'banned'])->count(),
                'unverified' => User::filter(['status' => 'unverified'])->count(),
                'premium' => User::filter(['status' => 'premium'])->count(),
            ]),
        ];
    }

    public function show (User $user) : View {
        if (AdminPreferences::usesNewAdmin()) {
            return $this->record($user);
        }

        $user->loadCount(['subscribers', 'videos', 'comments', 'interactions', 'subscriptions']);
        $user->load(['videos' => fn($q) => $q->latest()->limit(10), 'premium_subscription']);

        return view('admin.users.show', compact('user'));
    }

    public function edit (User $user) : View {
        if (AdminPreferences::usesNewAdmin()) {
            return $this->record($user);
        }

        return view('admin.users.edit', compact('user'));
    }

    /**
     * The new admin's one page per member (its edit and details addresses): the form, the numbers and the 5 latest
     * videos, each loaded once.
     */
    private function record(User $user): View
    {
        $user->loadCount(['subscribers', 'videos', 'comments']);
        $user->load([
            'videos' => fn ($query) => $query->without('thumbnail')->latest()->limit(5),
            'premium_subscription.plan:id,name',
        ]);

        return view('admin.users.record', compact('user'));
    }

    public function update (User $user, Request $request) : RedirectResponse {

        // The same rule as a member's own new password (Profile > Password).
        $password = Password::min(config('validation.user.password.min', 6));

        if (config('site.require_strong_password', false)) {
            $password = $password->mixedCase()->numbers()->symbols();
        }

        $validated = $request->validate([
            'username'    => ['required', 'string', 'max:'.config('validation.user.username.max', 50), Rule::unique('users')->ignore($user->id)],
            'email'       => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'description' => ['nullable', 'string', 'max:'.config('validation.user.description.max', 5000)],
            'country'     => ['nullable', 'string', 'max:2'],
            'is_admin'    => ['nullable', 'boolean'],
            'password'    => ['nullable', 'string', $password],
        ]);

        $user->update([
            'username' => $validated['username'],
            'email' => $validated['email'],
            'description' => $validated['description'] ?? $user->description,
            'country' => $validated['country'] ?? $user->country,
            'is_admin' => $request->has('is_admin'),
        ]);

        if ($request->filled('password')) {
            // The User model hashes the password it is given (User::password()). Hashing it here as well stored a hash
            // of the hash, so nobody could sign in with the password the admin typed.
            $user->update([
                'password' => (string) $validated['password'],
            ]);
        }

        return redirect()
            ->route('admin.users.edit', $user)
            ->withSuccess('User updated successfully.');
    }

    public function ban (User $user, Request $request) : RedirectResponse {

        $user->update([
            'banned_at' => now()
        ]);

        if ($request->has('ban_videos')){
            $user->videos()->update([
                'banned_at' => now(),
                'status' => VideoStatus::BANNED
            ]);
        }

        if ($request->has('ban_comments')){
            $user->comments()->update([
                'banned_at' => now(),
            ]);
        }

        UserBanned::dispatch($user);

        return redirect()->to(url()->previous())->withSuccess('User banned successfully.');
    }

    public function unban (User $user) : RedirectResponse {

        $user->update([
            'banned_at' => null
        ]);

        return redirect()->to(url()->previous())->withSuccess('User unbanned successfully.');
    }

    public function toggleAdmin (User $user) : RedirectResponse {

        // As the bulk action: an admin never takes their own rights away (they would be locked out of the admin).
        if ($user->is_admin && $user->id === auth()->id()) {
            return redirect()->to(url()->previous())->withErrors(['user' => __('You cannot remove your own administrator rights.')]);
        }

        // is_admin is not mass-assignable (User::$guarded): update() dropped it without a word, so set it directly.
        $user->forceFill([
            'is_admin' => !$user->is_admin
        ])->save();

        $status = $user->is_admin ? 'promoted to admin' : 'removed from admin';

        return redirect()->to(url()->previous())->withSuccess("User {$status} successfully.");
    }

    /**
     * Members > one member > "View-only demo account" (users.is_view_only, never mass-assignable): view_only=1 turns it
     * on, 0 off, so a second click or a reloaded form changes nothing. Only an administrator who is not view-only
     * may switch it (the ViewOnlyDemo guard refuses a view-only account before this; this is the second lock), and
     * never on their own account (they could not switch it back).
     */
    public function viewOnly (User $user, Request $request) : RedirectResponse {

        $actor = $request->user();

        abort_unless($actor instanceof User && $actor->is_admin && ! ViewOnlyDemo::isViewOnlyUser($actor), 403);

        $request->validate(['view_only' => ['required', 'boolean']]);

        if ((int) $user->getKey() === (int) $actor->getKey()) {
            return redirect()->to(url()->previous())->withErrors(['user' => __('You cannot make your own account view-only.')]);
        }

        // Before "Finish update" has added the column there is nothing to switch.
        if (! ViewOnlyDemo::available($user)) {
            return redirect()->to(url()->previous())->withErrors(['user' => __('These choices can be saved after the update is finished. Open Home and click Finish update.')]);
        }

        $on = $request->boolean('view_only');

        // A plain update: no model events (a member with a Stripe id would be sent to Stripe for an admin flag).
        User::query()->whereKey($user->getKey())->toBase()->update([ViewOnlyDemo::COLUMN => $on]);

        return redirect()->to(url()->previous())->withSuccess($on
            ? __('This account is now view-only: it can look at everything, but nothing it does is saved.')
            : __('This account can make changes again.'));
    }

    public function confirm (User $user) : RedirectResponse {
        $user->markEmailAsVerified();
        return redirect()->to(url()->previous())->withSuccess('User email confirmed.');
    }

    public function unverify (User $user) : RedirectResponse {
        $user->update(['email_verified_at' => null]);
        return redirect()->to(url()->previous())->withSuccess('Email verification revoked.');
    }

    public function delete (User $user) : RedirectResponse {

        $user->notify(new DeleteAccount());

        // forceDelete triggers UserObserver.forceDeleting which handles full cascade:
        // videos, comments, playlists, interactions, subscriptions, notifications,
        // views, activity, reports, favorites, tokens, premium, transactions,
        // searches, avatar/banner files
        $user->forceDelete();

        return redirect()->route('admin.users.index')->withSuccess('User deleted permanently.');
    }

    public function export (Request $request, ExportService $exportService): RedirectResponse {

        $exportService->generate(UsersExport::class, $request->query());

        return redirect()
            ->route('admin.users.index', $request->query())
            ->withSuccess('Your export has been queued. you will receive a notification when it is completed.');
    }
}
