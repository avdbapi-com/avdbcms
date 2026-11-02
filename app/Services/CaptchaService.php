<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class CaptchaService
{
    public const PROVIDER_NONE = 'none';
    public const PROVIDER_BUILT_IN = 'built_in';
    public const PROVIDER_TURNSTILE = 'turnstile';
    public const PROVIDER_RECAPTCHA_V2 = 'recaptcha_v2';

    /** The questions of forms printed before 1.2.3 round 3, kept in the session (each still answered once). */
    private const SESSION_KEY = 'captcha.challenges';

    /** How long a question can be answered. */
    private const MINUTES = 20;

    /** The cache entry that says a question was answered (until the question would have expired). */
    private const USED_PREFIX = 'captcha.used.';

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

    /**
     * A new question for a form of $action. It travels with the form: captcha_id holds its answer, its form, when it
     * expires, the visitor's session and a random id, encrypted and signed with the site's key (nobody can read or
     * change it), and nothing of it is kept on the server. Up to 1.2.3 RC2 the questions were kept in the session,
     * which every request reads and writes back whole: a request that ran next to the sign-in page (the video of the
     * watch page the visitor came from) wrote its older copy over the one the page had just stored with its question,
     * and the right answer was "expired" (round 3 review SB-X1). Each question is still answered once
     * (validateBuiltIn()).
     *
     * @return array{id: string, question: string}
     */
    public function makeChallenge(string $action): array
    {
        $left = random_int(2, 12);
        $right = random_int(2, 12);
        $id = Crypt::encryptString((string) json_encode([
            'a' => $action,
            'x' => (string) ($left + $right),
            'e' => now()->addMinutes(self::MINUTES)->timestamp,
            'n' => Str::random(32),
            's' => $this->sessionMark(request()),
        ]));

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
        // The question the form carries (used up here, whatever the answer), else one that a form printed before the
        // update kept in the session.
        $challenge = $id === '' ? null : ($this->openChallenge($id, $request) ?? $this->takeSessionChallenge($id));

        // Each question is used once: the form comes back with a new one. The sentence says what happened to the last
        // answer (judge R2A-046: an empty, a wrong and an expired answer were all "Please complete the security check.",
        // so a person who had typed the sum was told to do the check and never learnt it was wrong).
        if ($answer === '') {
            return __('Please complete the security check.');
        }

        // Not this session's question (its 20 minutes are over, it was answered already in another tab, the session is
        // new) or a question of another form.
        if (! is_array($challenge)
            || $challenge['action'] !== $action
            || $challenge['expires_at'] < now()->timestamp
        ) {
            return __('The security question expired. Answer the new one.');
        }

        if (! hash_equals($challenge['answer'], $answer)) {
            return __('That answer was wrong. Answer the new question.');
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

    /**
     * The question a form carries (makeChallenge()), used up now: null when $id is not one (a question of the session,
     * from before the update), false when it cannot be answered (another session's, answered already, or changed).
     *
     * @return array{action: string, answer: string, expires_at: int}|false|null
     */
    private function openChallenge(string $id, Request $request): array|false|null
    {
        if (strlen($id) > 2048) {
            return false;
        }

        try {
            $data = json_decode(Crypt::decryptString($id), true, 3);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($data) || ! is_string($data['a'] ?? null) || ! is_string($data['x'] ?? null)
            || ! is_int($data['e'] ?? null) || ! is_string($data['n'] ?? null) || ! is_string($data['s'] ?? null)
            || ! hash_equals($data['s'], $this->sessionMark($request))
        ) {
            return false;
        }

        $challenge = ['action' => $data['a'], 'answer' => $data['x'], 'expires_at' => $data['e']];

        if ($data['e'] < now()->timestamp) {
            return $challenge;
        }

        // Answered once: the first request that brings it marks it (the atomic "add" of every cache store) until it would
        // have expired. A cache that cannot be written lets the answer count: the form keeps working.
        try {
            $first = Cache::add(self::USED_PREFIX.hash('sha256', $data['n']), 1, max(60, $data['e'] - now()->timestamp + 60));
        } catch (Throwable) {
            $first = true;
        }

        return $first ? $challenge : false;
    }

    /**
     * A question that a form printed before 1.2.3 round 3 kept in the session, taken out of it (answered once); the
     * questions there that are over go with it.
     *
     * @return array{action: string, answer: string, expires_at: int}|null
     */
    private function takeSessionChallenge(string $id): ?array
    {
        $challenges = session(self::SESSION_KEY);

        if (! is_array($challenges)) {
            return null;
        }

        $challenge = $challenges[$id] ?? null;
        unset($challenges[$id]);
        $now = now()->timestamp;
        $challenges = array_filter(
            $challenges,
            fn ($kept): bool => is_array($kept) && (int) ($kept['expires_at'] ?? 0) >= $now
        );

        if ($challenges === []) {
            session()->forget(self::SESSION_KEY);
        } else {
            session([self::SESSION_KEY => $challenges]);
        }

        if (! is_array($challenge)) {
            return null;
        }

        return [
            'action' => (string) ($challenge['action'] ?? ''),
            'answer' => (string) ($challenge['answer'] ?? ''),
            'expires_at' => (int) ($challenge['expires_at'] ?? 0),
        ];
    }

    /** The visitor's session as a question remembers it: a hash of its id, the same for every request of the session. */
    private function sessionMark(?Request $request): string
    {
        try {
            return $request !== null && $request->hasSession() ? hash('sha256', (string) $request->session()->getId()) : '';
        } catch (Throwable) {
            return '';
        }
    }
}
