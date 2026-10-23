<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AccountResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class LoginController
{
    public function login (LoginRequest $request) : AccountResource|JsonResponse {

        $credentials = $request->validated();

        $user = User::query()
            ->where('username', $credentials['username'])
            ->orWhere('email', $credentials['username'])
            ->first();

        if ($user && Hash::check($credentials['password'], $user->password)) {
            $expiration = config('sanctum.expiration');
            $expiresAt = $expiration ? now()->addMinutes($expiration) : null;
            $token = $user->createToken((string) $request->input('device_name', 'api-token'), ['api'], $expiresAt)->plainTextToken;

            return (new AccountResource($user))->withToken($token);
        }

        return response()->json([
            'message' => __('auth.failed_api')
        ], Response::HTTP_UNAUTHORIZED);
    }
}
