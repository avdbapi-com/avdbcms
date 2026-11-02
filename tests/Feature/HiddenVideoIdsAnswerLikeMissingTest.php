<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Support\OwnerOnlyVideoRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Round 2a R2A-062: on R1-090's sibling routes (the draft checks and pictures of an upload, the thumbnails list, who rated
 * a video) and the studio's video pages and actions, whether a video id exists could still be read: 403 against 404
 * (create-status, the edit routes), "Not Found" against the missing id's sentence (the thumbnails list), and the headers
 * of the middleware that only run once a video is found (Content-Language, Vary, X-RateLimit-*, the view-only demo's
 * Cache-Control and its refusal of a change). Another member's video is now not found at the route binding, exactly like
 * an id that does not exist: the same status, body and headers. The channel and an administrator go on as before.
 */
class HiddenVideoIdsAnswerLikeMissingTest extends TestCase
{
    use RefreshDatabase;

    /** The owner-only abilities of VideoPolicy: a route checking one of them answers only the video's channel. */
    private const OWNER_ONLY_ABILITIES = ['view', 'update', 'delete', 'thumbnails', 'interactions', 'pin', 'unpin'];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.default_locale' => 'en', 'cache.limiter' => 'array']);
        Cache::flush();
        $this->owner = User::factory()->create();
    }

    public function test_every_owner_only_video_route_is_in_the_list(): void
    {
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('video', $route->parameterNames(), true) || str_starts_with((string) $route->getName(), 'admin.')) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && preg_match('/^can:([a-z]+),video$/', $middleware, $m) === 1
                    && in_array($m[1], self::OWNER_ONLY_ABILITIES, true)
                    && ! array_key_exists((string) $route->getName(), OwnerOnlyVideoRoutes::ROUTES)) {
                    $missing[] = $route->getName().' ('.$middleware.')';
                }
            }
        }

        $this->assertSame([], $missing, 'a route of a video that answers only its channel must be in OwnerOnlyVideoRoutes::ROUTES');

        foreach (array_keys(OwnerOnlyVideoRoutes::ROUTES) as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name), $name.' is a route');
        }
    }

    public function test_a_hidden_id_answers_exactly_like_a_missing_one(): void
    {
        $member = User::factory()->create();
        $demo = User::factory()->create(['email_verified_at' => now()]);
        $demo->forceFill(['is_view_only' => true])->save();
        $hidden = [
            'private' => Video::factory()->for($this->owner)->withStatus(VideoStatus::PRIVATE)->create(),
            'draft' => Video::factory()->for($this->owner)->withStatus(VideoStatus::DRAFT)->create(),
            'public' => Video::factory()->for($this->owner)->withStatus(VideoStatus::PUBLIC)->create(['published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]),
            'banned' => Video::factory()->for($this->owner)->withStatus(VideoStatus::BANNED)->banned()->create(),
        ];
        $missing = new Video(['uuid' => '00000000-0000-4000-8000-000000000000']);
        $missing->id = (int) Video::query()->max('id') + 1000;

        foreach (['a member' => $member, 'the view-only demo account' => $demo] as $who => $account) {
            foreach ($this->routes() as [$method, $name, $api]) {
                foreach ($hidden as $kind => $video) {
                    $answerMissing = $this->ask($account, $method, $name, $missing, $api);
                    $answerHidden = $this->ask($account, $method, $name, $video, $api);
                    $context = $who.', '.$method.' '.$name.', '.$kind;

                    $this->assertSame($answerMissing->getStatusCode(), $answerHidden->getStatusCode(), $context.': status');
                    $this->assertSame(404, $answerHidden->getStatusCode(), $context);
                    $this->assertSame($this->body($answerMissing, $missing), $this->body($answerHidden, $video), $context.': body');
                    $this->assertSame($this->headers($answerMissing), $this->headers($answerHidden), $context.': headers');
                }
            }
        }

        // nothing of the channel's videos changed
        foreach ($hidden as $kind => $video) {
            $this->assertNotNull(Video::query()->find($video->id), $kind);
        }
    }

    public function test_the_channel_and_an_administrator_go_on_as_before(): void
    {
        $draft = Video::factory()->for($this->owner)->withStatus(VideoStatus::DRAFT)->create();
        $banned = Video::factory()->for($this->owner)->withStatus(VideoStatus::BANNED)->banned()->create();
        $public = Video::factory()->for($this->owner)->withStatus(VideoStatus::PUBLIC)->create(['published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);

        // the channel: its own answers, the policies' refusals included
        $this->actingAs($this->owner, 'sanctum')->getJson(route('videos.create-status', $draft))->assertOk();
        $this->actingAs($this->owner, 'sanctum')->getJson(route('thumbnails.list', $public))->assertOk();
        $this->actingAs($this->owner)->get(route('user.videos.edit', $public))->assertOk();
        $this->actingAs($this->owner)->get(route('user.videos.edit', $banned))->assertForbidden();

        // an administrator: what VideoPolicy::before() gives (the studio's edit page, the thumbnails, who rated it)
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('user.videos.edit', $public))->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson(route('thumbnails.list', $public))->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson(route('interactions.list', $public).'?filter=up')->assertOk();
        // ... and not what it does not give (deleting another member's video): not found, like for anybody
        $this->actingAs($admin, 'sanctum')->deleteJson(route('videos.delete', $public))->assertNotFound();
        $this->assertNotNull(Video::query()->find($public->id));

        // from an address outside the admin IP lock an administrator is a member there
        config(['site.admin_ip_whitelist' => '203.0.113.7']);
        $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->get(route('user.videos.edit', $public))->assertNotFound();
    }

    /** @return list<array{string, string, bool}> method, route name, API */
    private function routes(): array
    {
        return [
            ['GET', 'interactions.list', true],
            ['GET', 'thumbnails.list', true],
            ['GET', 'videos.create-status', true],
            ['POST', 'videos.generate-thumbnails', true],
            ['POST', 'videos.generate-server-thumbnails', true],
            ['DELETE', 'videos.delete', true],
            ['GET', 'user.videos.show', false],
            ['GET', 'user.videos.edit', false],
            ['GET', 'user.videos.create', false],
            ['PUT', 'user.videos.update', false],
            ['POST', 'user.videos.store', false],
            ['DELETE', 'user.videos.destroy', false],
            ['POST', 'user.videos.pin', false],
            ['POST', 'user.videos.unpin', false],
        ];
    }

    private function ask(User $account, string $method, string $name, Video $video, bool $api): TestResponse
    {
        // every pair starts from the same limiter state (the upload routes allow 10 a minute, server thumbnails 2)
        Cache::store('array')->flush();
        $this->app['auth']->forgetGuards();
        $url = route($name, $video, false);

        return $api
            ? $this->actingAs($account, 'sanctum')->json($method, $url, [])
            : $this->actingAs($account)->call($method, $url, [], [], [], ['HTTP_ACCEPT' => 'text/html']);
    }

    private function body(TestResponse $response, Video $video): string
    {
        // the address in the missing-id sentence ("The route api/videos/<id>/... could not be found."): the uuid first (the
        // factory's uuid is a number that may hold the id's digits), each only as a path segment
        // (also written %2F-encoded in the page's language links and as JSON with an escaped slash)
        $body = (string) $response->getContent();
        $body = str_replace(['/'.$video->uuid, '%2F'.$video->uuid, '\\/'.$video->uuid], ['/<ID>', '%2F<ID>', '\\/<ID>'], $body);
        $body = (string) preg_replace('#(/|%2F|\\\\/)'.preg_quote((string) $video->id, '#').'(?=[/?"&%\s\\\\]|$)#', '$1<ID>', $body);

        return (string) preg_replace(['/name="csrf-token" content="[^"]+"/', '/name="_token" value="[^"]+"/', '/nonce="[^"]+"/'], ['csrf', '_token', 'nonce'], $body);
    }

    /** @return array<string, string> every header but the date, the cookies and the length */
    private function headers(TestResponse $response): array
    {
        $headers = [];

        foreach ($response->headers->all() as $name => $values) {
            if (in_array($name, ['date', 'set-cookie', 'content-length'], true)) {
                continue;
            }

            $headers[$name] = implode(', ', $values);
        }

        ksort($headers);

        return $headers;
    }
}
