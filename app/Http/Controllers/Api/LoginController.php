<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AccountResource;
use App\Models\User;
use App\Support\PasswordCheck;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class LoginController
{
    public function login (LoginRequest $request) : AccountResource|JsonResponse {

        $credentials = $request->validated();

        $user = User::query()
            ->where('username', $credentials['username'])
            ->orWhere('email', $credentials['username'])
            ->first();

        // One password check whether an account has this name or address or not (round 3 R3-021, App\Support\PasswordCheck):
        // the time of the answer no longer tells which addresses have an account.
        $passwordMatches = PasswordCheck::matches($user, $credentials['password']);

        if ($user && $passwordMatches) {
            // A banned account gets no new token (the API refuses its old ones too: IsBanned).
            if ($user->is_banned) {
                return response()->json(['message' => __('Your account was suspended')], Response::HTTP_FORBIDDEN);
            }

            PasswordCheck::rehashAfterSignIn($user, $credentials['password']);

            // SANCTUM_TOKEN_EXPIRATION comes from .env as text ("43200"): a number of minutes, 0 or less for none.
            $expiration = (int) config('sanctum.expiration');
            $expiresAt = $expiration > 0 ? now()->addMinutes($expiration) : null;
            // A blank device name (an empty field arrives as null) names the token like the sign-up does: the member's
            // Devices list and the admin's API tokens show it.
            $deviceName = trim((string) $request->input('device_name', 'api-token')) ?: 'api-token';
            $token = $user->createToken($deviceName, ['api'], $expiresAt)->plainTextToken;

            return (new AccountResource($user))->withToken($token);
        }

        return response()->json([
            'message' => __('auth.failed_api')
        ], Response::HTTP_UNAUTHORIZED);
    }
}
