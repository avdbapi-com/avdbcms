<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Http\Middleware\BackgroundRequest;
use App\Http\Middleware\ReadOnlySession;
use App\Models\User;
use App\Models\Video;
use App\Services\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Round 3 review SB-X1: a guest's sign-in from a watch page failed once with "The security question expired. Answer the
 * new one." The session is read and written back whole by every request; the page's own video request (/video/file/...,
 * the <video> element's request) had read it before the sign-in page stored its question, ended after it and wrote its
 * older copy over it (lane SB's box run: the video request answered 198 ms after the sign-in page had started, and the
 * right answer came back as expired).
 *  - The question now travels with its form (encrypted and signed, bound to the visitor's session, used once), so no
 *    request that ends next to the sign-in page can take it away.
 *  - The video's file and the player's view count read the session but never write it (like the pictures and the page's
 *    background requests), so the page's own requests never write an older copy of the session over a newer one.
 * A browser keeps its session's id between the sign-in page and the sign-in; the test client makes a new one for every
 * request unless the cookie is sent, so these tests send it.
 */
class SignInQuestionRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.captcha_provider' => CaptchaService::PROVIDER_BUILT_IN,
            'site.captcha_on_login' => true,
            'site.captcha_on_contact' => true,
            'site.honeypot_enabled' => true,
            'site.login_rate_limit' => 1000,
            'site.default_locale' => 'en',
            'cache.limiter' => 'array',
        ]);
        Cache::flush();
    }

    public function test_a_request_that_ends_after_the_sign_in_page_never_takes_its_question_or_token_away(): void
    {
        $member = User::factory()->create(['password' => 'password']);
        $login = ['username' => $member->email, 'password' => 'password'];
        $browser = Str::random(40);
        // the site's session store: every request reads the session from it and writes it back whole
        $handler = app('session')->driver()->getHandler();

        // the visitor's session exists (an earlier page)
        $this->asBrowser($browser)->get('/login')->assertOk();

        // the watch page's video request reads the session ...
        $this->nextRequest();
        $video = new Store((string) config('session.cookie'), $handler, $browser);
        $video->start();

        // ... the sign-in page reads it, prints its question and the form's token, and stores the session ...
        $this->nextRequest();
        $page = (string) $this->asBrowser($browser)->get('/login?redirect='.rawurlencode('/video/abc'))->assertOk()->getContent();
        [$id, $answer] = $this->questionOf($page);
        $this->assertSame(1, preg_match('#name="_token" value="([^"]+)"#', $page, $token));

        // ... and the video request ends: its copy, older than the sign-in page's, is written over it
        $video->setPreviousUrl('http://localhost/video/file/abc.mp4');
        $video->save();

        // the form's token is still the session's, and the right answer signs in
        $this->assertSame($token[1], unserialize((string) $handler->read($browser))['_token'] ?? null, 'the form\'s token');
        $this->nextRequest();
        $this->asBrowser($browser)->from('/login')->post('/login', $login + ['_token' => $token[1], 'captcha_id' => $id, 'captcha_answer' => $answer])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($member);
    }

    public function test_the_question_is_carried_by_the_form_and_answered_once(): void
    {
        $member = User::factory()->create(['password' => 'password']);
        $login = ['username' => $member->email, 'password' => 'password'];
        $browser = Str::random(40);

        $page = (string) $this->asBrowser($browser)->get('/login')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#name="captcha_id" value="eyJ[A-Za-z0-9+/=]{100,}"#', $page, 'the question, encrypted and signed');
        $this->assertNull(session('captcha.challenges'), 'nothing of it kept in the session');
        [$id, $answer] = $this->questionOf($page);

        // another browser (another session) cannot use it
        $this->asBrowser(Str::random(40))->from('/login')->post('/login', $login + ['captcha_id' => $id, 'captcha_answer' => $answer])
            ->assertSessionHasErrors('captcha');
        $this->assertSame('The security question expired. Answer the new one.', session('errors')->first('captcha'));
        $this->assertGuest();

        // the browser that got it signs in with it, once
        $this->flushSession();
        $page = (string) $this->asBrowser($browser)->get('/login')->assertOk()->getContent();
        [$id, $answer] = $this->questionOf($page);
        $this->asBrowser($browser)->from('/login')->post('/login', $login + ['captcha_id' => $id, 'captcha_answer' => $answer])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($member);

        Auth::logout();
        $this->flushSession();
        $this->asBrowser($browser)->from('/login')->post('/login', $login + ['captcha_id' => $id, 'captcha_answer' => $answer])
            ->assertSessionHasErrors('captcha');
        $this->assertSame('The security question expired. Answer the new one.', session('errors')->first('captcha'), 'one sign-in per question');
        $this->assertGuest();
    }

    public function test_a_question_is_used_up_by_a_wrong_answer_and_cannot_be_moved_changed_or_kept(): void
    {
        $member = User::factory()->create(['password' => 'password']);
        $login = ['username' => $member->email, 'password' => 'password'];
        $browser = Str::random(40);

        // a wrong answer uses the question up
        [$id, $answer] = $this->questionOf((string) $this->asBrowser($browser)->get('/login')->getContent());
        $this->assertSame('That answer was wrong. Answer the new question.', $this->captchaError($browser, $login, $id, '999'));
        $this->assertSame('The security question expired. Answer the new one.', $this->captchaError($browser, $login, $id, $answer));

        // a question of another form (the contact form's, same browser)
        [$contactId, $contactAnswer] = $this->questionOf((string) $this->asBrowser($browser)->get('/contact')->getContent());
        $this->assertSame('The security question expired. Answer the new one.', $this->captchaError($browser, $login, $contactId, $contactAnswer));

        // a question made up with the site's key for another session, a changed one
        $forged = Crypt::encryptString((string) json_encode(['a' => 'login', 'x' => '4', 'e' => now()->addMinutes(5)->timestamp, 'n' => 'x', 's' => 'not-this-session']));
        $this->assertSame('The security question expired. Answer the new one.', $this->captchaError($browser, $login, $forged, '4'));
        [$id, $answer] = $this->questionOf((string) $this->asBrowser($browser)->get('/login')->getContent());
        $this->assertSame('The security question expired. Answer the new one.', $this->captchaError($browser, $login, substr($id, 0, -6).'AAAAAA', $answer));

        // a question older than 20 minutes
        [$id, $answer] = $this->questionOf((string) $this->asBrowser($browser)->get('/login')->getContent());
        $this->travel(21)->minutes();
        $this->assertSame('The security question expired. Answer the new one.', $this->captchaError($browser, $login, $id, $answer));
        $this->assertGuest();

        // an empty answer says so (and uses the question up too)
        [$id, $answer] = $this->questionOf((string) $this->asBrowser($browser)->get('/login')->getContent());
        $this->assertSame('Please complete the security check.', $this->captchaError($browser, $login, $id, ''));
        $this->assertSame('The security question expired. Answer the new one.', $this->captchaError($browser, $login, $id, $answer));
        $this->assertGuest();
    }

    public function test_the_video_file_reads_the_session_and_never_writes_it(): void
    {
        $route = Route::getRoutes()->getByName('video.file');
        // with KEEP_ALIVE: the stored session's time moves on and its cookie is renewed (round 3 check SB-V1-01;
        // VideoFileKeepAliveTest)
        $this->assertContains(ReadOnlySession::class.':'.ReadOnlySession::KEEP_ALIVE, $route->gatherMiddleware());
        foreach (ReadOnlySession::REPLACES as $removed) {
            $this->assertContains($removed, $route->excludedMiddleware(), 'video.file still runs '.$removed);
        }

        Storage::fake('local');
        $owner = User::factory()->create();
        $video = Video::factory()->withStatus(VideoStatus::PRIVATE)->for($owner)->create(['file' => 'sb-x1-'.Str::random(8).'.mp4']);
        Storage::disk('local')->put(Video::VIDEO_FOLDER.'/'.$video->file, 'MP4-BYTES');

        // the owner's session, with a one-time message waiting for its page: the video's request reads the session (a
        // private video is its owner's), uses nothing up and writes nothing
        $id = $this->storedSession($owner, ['status' => 'Saved.'], ['status']);
        $stored = app('session')->driver()->getHandler()->read($id);

        $response = $this->withCookie(config('session.cookie'), $id)->get(route('video.file', $video, false));
        $response->assertOk();
        $this->assertSame($id, $response->getCookie((string) config('session.cookie'))?->getValue(), 'the same session cookie, renewed: never another one');
        $this->assertSame($stored, app('session')->driver()->getHandler()->read($id), 'the stored session changed');

        // a guest still gets no private video, and the refusal gives no session cookie either. Since round 3 R2R-07
        // (App\Support\HiddenRouteModels: a video the asker may not see is not found at the route binding) the refusal is
        // the answer of a file that does not exist - 404, the same headers, cookies and page - no longer 403 "This video
        // is private", which told that the link is a private video's
        $refused = $this->asGuest()->get(route('video.file', $video, false))->assertNotFound();
        $this->assertNull($refused->getCookie((string) config('session.cookie')), 'a session cookie for a guest\'s refused video');
        $missing = $this->asGuest()->get(route('video.file', ['video' => 'nothing-here.mp4'], false))->assertNotFound();
        $this->assertSame($this->answerOf($missing, 'nothing-here.mp4'), $this->answerOf($refused, $video->file), 'not the missing video\'s answer');
    }

    public function test_the_players_view_count_never_writes_the_session(): void
    {
        $this->assertContains('videos.view', BackgroundRequest::POST_ROUTES);
        $this->assertContains('POST', Route::getRoutes()->getByName('videos.view')->methods());

        $video = Video::factory()->withStatus(VideoStatus::PUBLIC)->for(User::factory())->create(['file' => 'sb-x1-'.Str::random(8).'.mp4']);
        $token = Str::random(40);
        $id = $this->storedSession(null, ['status' => 'Saved.'], ['status'], $token);
        $stored = app('session')->driver()->getHandler()->read($id);

        $this->withCookie(config('session.cookie'), $id)
            ->withHeaders(['Referer' => 'http://localhost/video/x', 'Origin' => 'http://localhost', 'X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
            ->post('/api/videos/'.$video->uuid.'/view')
            ->assertNoContent();

        $this->assertSame($stored, app('session')->driver()->getHandler()->read($id), 'the stored session changed');

        // any other POST still writes its session as before (only the view count is a background request)
        $view = Request::create('/api/videos/'.$video->uuid.'/view', 'POST');
        $view->setRouteResolver(fn () => Route::getRoutes()->getByName('videos.view'));
        $this->assertTrue(BackgroundRequest::is($view));
        $signUp = Request::create('/register', 'POST');
        $signUp->setRouteResolver(fn () => Route::getRoutes()->getByName('registration.perform'));
        $this->assertFalse(BackgroundRequest::is($signUp));
    }

    private function asBrowser(string $sessionId): static
    {
        return $this->withCookie((string) config('session.cookie'), $sessionId);
    }

    /** A guest again: nobody signed in, a new session object, no cookie of an earlier request. */
    private function asGuest(): static
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->defaultCookies = [];

        return $this;
    }

    /**
     * An answer as the visitor gets it, without what differs by nature: its status, its headers (but the date, the length
     * and the cookies' values), the names of its cookies and its page ($file, the address's value, as <ID>; the form's
     * token and the nonces left out).
     *
     * @return array{0: int, 1: array<string, list<string|null>>, 2: list<string>, 3: string}
     */
    private function answerOf(TestResponse $response, string $file): array
    {
        $headers = $response->headers->all();
        unset($headers['date'], $headers['content-length'], $headers['set-cookie']);
        ksort($headers);
        $cookies = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
        sort($cookies);
        $page = (string) preg_replace(
            ['/name="csrf-token" content="[^"]+"/', '/name="_token" value="[^"]+"/', '/nonce="[^"]+"/'],
            ['csrf-token', '_token', 'nonce'],
            str_replace($file, '<ID>', (string) $response->getContent()),
        );

        return [$response->getStatusCode(), $headers, $cookies, $page];
    }

    /** @param  array<string, string>  $login */
    private function captchaError(string $browser, array $login, string $id, string $answer): ?string
    {
        $this->asBrowser($browser)->from('/login')->post('/login', $login + ['captcha_id' => $id, 'captcha_answer' => $answer]);

        return session('errors')?->first('captcha') ?: null;
    }

    /**
     * The next request of the browser: a request starts with an empty session object that the session middleware fills
     * from the store (the test client keeps one object for all its requests).
     */
    private function nextRequest(): void
    {
        app('session')->driver()->flush();
        Auth::forgetGuards();
    }

    /** @return array{0: string, 1: string} the question's id and its right answer, read from a form */
    private function questionOf(string $page): array
    {
        $this->assertSame(1, preg_match('#name="captcha_id" value="([^"]+)"#', $page, $id), 'a question in the form');
        $this->assertSame(1, preg_match('#what is <bdi dir="ltr">(\d+) \+ (\d+)</bdi>\?#', $page, $sum));

        return [html_entity_decode($id[1]), (string) ((int) $sum[1] + (int) $sum[2])];
    }

    /**
     * A session stored under a new id (the browser holds the id in its cookie), with $flash waiting for its page.
     *
     * @param  array<string, string>  $flash
     * @param  list<string>  $new
     */
    private function storedSession(?User $user, array $flash = [], array $new = [], ?string $token = null): string
    {
        $id = Str::random(40);
        $data = $flash + [
            '_token' => $token ?? Str::random(40),
            '_previous' => ['url' => url('/'), 'route' => 'pages.home'],
            '_flash' => ['old' => [], 'new' => $new],
        ];

        if ($user !== null) {
            $data[Auth::guard('web')->getName()] = $user->getAuthIdentifier();
        }

        app('session')->driver()->getHandler()->write($id, serialize($data));

        return $id;
    }
}
