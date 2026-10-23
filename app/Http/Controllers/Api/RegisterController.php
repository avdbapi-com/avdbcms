<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\AccountResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;

class RegisterController
{
    public function register(RegisterRequest $request, UserService $userService): AccountResource|JsonResponse
    {
        if (! config('site.registration_enabled', true) || ! config('site.api_registration', false)) {
            return response()->json([
                'message' => __('Registration is currently disabled.'),
            ], 403);
        }

        $validated = $request
            ->safe()
            ->merge([
                'slug' => User::generateSlug($request->string('username')->toString()),
                'is_admin' => null,
            ])
            ->except(['cgu', 'device_name']);

        // API clients are stateless. The web registration path logs into the
        // session, which is unavailable for a mobile token request.
        $user = $userService->register($validated, false);
        $expiration = config('sanctum.expiration');
        $expiresAt = $expiration ? now()->addMinutes($expiration) : null;
        $deviceName = trim((string) $request->input('device_name', 'api-token')) ?: 'api-token';
        $token = $user->createToken($deviceName, ['api'], $expiresAt)->plainTextToken;

        return (new AccountResource($user))
            ->withToken($token)
            ->response()
            ->setStatusCode(201);
    }
}
