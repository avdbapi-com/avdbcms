<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class CaptchaService
{
    public const PROVIDER_NONE = 'none';
    public const PROVIDER_BUILT_IN = 'built_in';
    public const PROVIDER_TURNSTILE = 'turnstile';
    public const PROVIDER_RECAPTCHA_V2 = 'recaptcha_v2';

    private const SESSION_KEY = 'captcha.challenges';

    public static function providers(): array
    {
        return [
            self::PROVIDER_NONE => __('Disabled'),
            self::PROVIDER_BUILT_IN => __('Built-in challenge (no setup)'),
            self::PROVIDER_TURNSTILE => __('Cloudflare Turnstile'),
            self::PROVIDER_RECAPTCHA_V2 => __('Google reCAPTCHA v2'),
        ];
    }

    public function provider(): string
    {
        $provider = (string) config('site.captcha_provider', self::PROVIDER_NONE);

        return array_key_exists($provider, self::providers()) ? $provider : self::PROVIDER_NONE;
    }

    public function enabledFor(string $action, ?Request $request = null): bool
    {
        $request ??= request();

        // Accept headers describe the response format, not whether a request
        // is a stateless API call. A browser can submit JSON (including the
        // normal web login/register forms), so do not let `expectsJson()` turn
        // off a configured challenge. Only the explicit API route namespace
        // is exempt because those clients do not have the session-backed web
        // challenge flow.
        $path = '/' . ltrim($request->path(), '/');
        $isApiRequest = str_starts_with($path, '/api/')
            || str_starts_with($path, '/index.php/api/');

        if ($isApiRequest) {
            return false;
        }

        $provider = $this->provider();
        if ($provider === self::PROVIDER_NONE || ! config("site.captcha_on_{$action}", false)) {
            return false;
        }

        if ($provider === self::PROVIDER_BUILT_IN) {
            return true;
        }

        return trim((string) config('site.captcha_site_key', '')) !== ''
            && trim((string) config('site.captcha_secret_key', '')) !== '';
    }

    public function makeChallenge(string $action): array
    {
        $this->purgeExpiredChallenges();

        $left = random_int(2, 12);
        $right = random_int(2, 12);
        $id = Str::random(40);
        $challenges = session(self::SESSION_KEY, []);
        $challenges[$id] = [
            'action' => $action,
            'answer' => (string) ($left + $right),
            'expires_at' => now()->addMinutes(20)->timestamp,
        ];

        if (count($challenges) > 10) {
            $challenges = array_slice($challenges, -10, null, true);
        }

        session([self::SESSION_KEY => $challenges]);

        return [
            'id' => $id,
            'question' => "{$left} + {$right}",
        ];
    }

    public function validate(Request $request, string $action): ?string
    {
        if (! $this->enabledFor($action, $request)) {
            return null;
        }

        return match ($this->provider()) {
            self::PROVIDER_BUILT_IN => $this->validateBuiltIn($request, $action),
            self::PROVIDER_TURNSTILE => $this->validateRemote(
                $request,
                'cf-turnstile-response',
                'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            ),
            self::PROVIDER_RECAPTCHA_V2 => $this->validateRemote(
                $request,
                'g-recaptcha-response',
                'https://www.google.com/recaptcha/api/siteverify'
            ),
            default => null,
        };
    }

    public function siteKey(): string
    {
        return trim((string) config('site.captcha_site_key', ''));
    }

    private function validateBuiltIn(Request $request, string $action): ?string
    {
        $id = (string) $request->input('captcha_id', '');
        $answer = trim((string) $request->input('captcha_answer', ''));
        $challenges = session(self::SESSION_KEY, []);
        $challenge = is_array($challenges) ? ($challenges[$id] ?? null) : null;

        if ($id !== '') {
            unset($challenges[$id]);
            session([self::SESSION_KEY => $challenges]);
        }

        if (! is_array($challenge)
            || ($challenge['action'] ?? '') !== $action
            || (int) ($challenge['expires_at'] ?? 0) < now()->timestamp
            || ! hash_equals((string) ($challenge['answer'] ?? ''), $answer)
        ) {
            return __('Please complete the security check.');
        }

        return null;
    }

    private function validateRemote(Request $request, string $field, string $endpoint): ?string
    {
        $token = trim((string) $request->input($field, ''));
        if ($token === '') {
            return __('Please complete the security check.');
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post($endpoint, [
                    'secret' => trim((string) config('site.captcha_secret_key', '')),
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);

            if (! $response->ok() || ! (bool) $response->json('success', false)) {
                return __('The security check failed. Please try again.');
            }
        } catch (Throwable) {
            return __('The security check could not be verified. Please try again.');
        }

        return null;
    }

    private function purgeExpiredChallenges(): void
    {
        $challenges = session(self::SESSION_KEY, []);
        if (! is_array($challenges)) {
            session()->forget(self::SESSION_KEY);
            return;
        }

        $now = now()->timestamp;
        $challenges = array_filter(
            $challenges,
            fn ($challenge): bool => is_array($challenge) && (int) ($challenge['expires_at'] ?? 0) >= $now
        );

        session([self::SESSION_KEY => $challenges]);
    }
}
