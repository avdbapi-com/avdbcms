<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Http\Middleware\UseChosenLanguage;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Models\Video;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * R1-050, the side of it on the pages (found while fixing the API side): the member pages' forms send their data to
 * plain addresses (/profile, /profile/videos/mass-visibility ...). What answers there before the web group's Language
 * middleware (the page token check's 419 page, the limits of the routes, the sign-in bounce) used the site's default
 * language although the member chose another one; it now uses the language the visitor chose (UseChosenLanguage).
 */
class WebRefusalLanguageTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'csrf-test-token-r2ca-0123456789abcdef01234';

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.limiter' => 'array', 'site.default_locale' => 'en', 'site.captcha_provider' => 'none']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_a_timed_out_form_of_a_plain_address_answers_in_the_chosen_language(): void
    {
        $this->checkTheTokenForReal();

        $page = $this->withSession(['app_locale' => 'fr', '_token' => self::TOKEN])
            ->from('/fr/login')->post('/login', ['_token' => 'stale-token', 'username' => 'x', 'password' => 'y'])
            ->assertStatus(419);

        $json = $this->withSession(['app_locale' => 'ar', '_token' => self::TOKEN])
            ->postJson('/logout', ['_token' => 'stale-token'])
            ->assertStatus(419);

        app()->setLocale('fr');
        $title = __('This page timed out');
        app()->setLocale('ar');
        $sentence = __('This page timed out. Reload it, then try again.');
        app()->setLocale('en');

        $this->assertNotSame(__('This page timed out'), $title);
        $this->assertStringContainsString('<title>'.e($title), (string) $page->getContent());
        $this->assertStringContainsString('lang="fr"', (string) $page->getContent());
        $this->assertSame($sentence, $json->json('message'));
        $this->assertNotSame(__('This page timed out. Reload it, then try again.'), $sentence);
    }

    public function test_a_limit_of_a_plain_address_answers_in_the_chosen_language(): void
    {
        foreach ([null, 'public', 'thumbnails', 'videos'] as $disk) {
            Storage::fake($disk);
        }
        $member = User::factory()->create();
        $video = Video::factory()->for($member, 'user')->withStatus(VideoStatus::PRIVATE)->create();

        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($member)->withSession(['app_locale' => 'de'])->from(route('user.videos.index'))
                ->post(route('user.videos.mass-visibility'), ['ids' => [$video->id], 'visibility' => $i % 2 ? 'public' : 'private'])
                ->assertRedirect();
        }

        // a new request starts in the site's language (the test application keeps the last request's)
        app()->setLocale('en');
        $refused = $this->actingAs($member)->withSession(['app_locale' => 'de'])
            ->postJson(route('user.videos.mass-visibility'), ['ids' => [$video->id], 'visibility' => 'unlisted'])
            ->assertStatus(429);

        app()->setLocale('de');
        $expected = RouteServiceProvider::waitMessage((int) $refused->headers->get('Retry-After'));
        app()->setLocale('en');

        $this->assertSame($expected, $refused->json('message'));
        $this->assertDoesNotMatchRegularExpression('/^Too many tries/', (string) $refused->json('message'));
    }

    public function test_without_a_chosen_language_the_site_language_answers_as_before(): void
    {
        $this->checkTheTokenForReal();

        $this->withSession(['_token' => self::TOKEN])->postJson('/logout', ['_token' => 'stale-token'])
            ->assertStatus(419)->assertJsonPath('message', 'This page timed out. Reload it, then try again.');

        // a language the site does not offer is ignored, as the Language middleware ignores it
        $this->withSession(['app_locale' => 'xx', '_token' => self::TOKEN])->postJson('/logout', ['_token' => 'stale-token'])
            ->assertStatus(419)->assertJsonPath('message', 'This page timed out. Reload it, then try again.');
    }

    public function test_the_address_language_wins_and_the_chosen_language_runs_right_after_the_session_starts(): void
    {
        $this->checkTheTokenForReal();
        $_SERVER['DETECTED_LOCALE'] = 'fr';
        app()->setLocale('fr'); // public/index.php, for an address under /fr

        $this->withSession(['app_locale' => 'de', '_token' => self::TOKEN])->postJson('/logout', ['_token' => 'stale-token'])
            ->assertStatus(419);
        $this->assertSame('fr', app()->getLocale(), 'the address\'s language, not the session\'s');

        $resolved = array_map(
            fn ($middleware) => is_string($middleware) ? explode(':', $middleware)[0] : get_class($middleware),
            app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('user.update')),
        );
        $chosen = array_search(UseChosenLanguage::class, $resolved, true);
        $this->assertIsInt($chosen);
        $this->assertGreaterThan(array_search(\Illuminate\Session\Middleware\StartSession::class, $resolved, true), $chosen);
        foreach ([VerifyCsrfToken::class, \App\Http\Middleware\Authenticate::class, \Illuminate\Routing\Middleware\ThrottleRequests::class, \App\Http\Middleware\Language::class] as $later) {
            foreach (array_keys($resolved, $later, true) as $at) {
                $this->assertLessThan($at, $chosen, $later);
            }
        }
    }

    private function checkTheTokenForReal(): void
    {
        $this->app->instance(VerifyCsrfToken::class, new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }
}
