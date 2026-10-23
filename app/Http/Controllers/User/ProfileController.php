<?php

namespace App\Http\Controllers\User;

use App\Events\Account\EmailUpdated;
use App\Events\Account\PasswordUpdated;
use App\Helpers\File;
use App\Http\Requests\UpdateUserRequest;
use App\Jobs\DeleteUser;
use App\Models\User;
use App\Support\ChannelCustomizationSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Intl\Countries;

class ProfileController
{
    public function edit(): View {
        $user = Auth::user()->load('premium_subscription');
        $billingUrl = $user->billingManagementUrl(route('user.edit'));

        return view('users.profile.edit', [
            'user' => $user,
            'billing_profile_url' => $billingUrl !== route('user.edit') ? $billingUrl : null,
            'countries' => Countries::getNames()
        ]);
    }

    public function update(UpdateUserRequest $request) : RedirectResponse {

        $user = auth()->user();

        $validated = collect($request->safe()->except(['email']))->merge([
            'avatar' => File::storeAndDelete($request->file('avatar'), User::AVATAR_FOLDER, $user->avatar),
            'banner' => File::storeAndDelete($request->file('banner'), User::BANNER_FOLDER, $user->banner),
        ])->toArray();

        if (array_key_exists('channel_customization', $validated) && config('site.channel_customization_enabled', true)) {
            $validated['channel_customization'] = ChannelCustomizationSettings::normalizeForStorage(
                $validated['channel_customization'] ?? [],
                $user->channel_customization ?? []
            );
        } elseif (array_key_exists('channel_customization', $validated)) {
            unset($validated['channel_customization']);
        }

        $user->update($validated);

        if ($request->get('email') && $request->get('email') !== $user->email && !$user->getTemporaryEmailForVerification()) {
            EmailUpdated::dispatch($request->get('email'));
        }

        if ($user->wasChanged('phone') && $user->getPhone()) {
            $request->user()->sendPhoneVerificationNotification();
        }

        return redirect()->route('user.edit')->with(['status' => 'Your profile information has been updated successfully !']);
    }

    public function updatePassword(Request $request): RedirectResponse {

        $password = Password::min(config('validation.user.password.min', 6));

        if (config('site.require_strong_password', false)) {
            $password = $password->mixedCase()->numbers()->symbols();
        }

        $request->validate([
            'new_password' => ['required', 'confirmed', $password]
        ]);

        PasswordUpdated::dispatch($request->get('new_password'));

        return redirect()->route('user.edit')->with(['status' => 'Your password has been updated successfully !']);
    }

    public function delete(): RedirectResponse {

        // Check if admin allows account self-deletion
        if (!config('site.allow_account_deletion', true)) {
            return redirect()->route('user.edit')
                ->with('error', __('Account deletion is currently disabled.'));
        }

        DeleteUser::dispatch(Auth::user());

        Auth::logout();

        return redirect()->route('pages.home');
    }

}
