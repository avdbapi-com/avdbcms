<?php

namespace Tests\Feature;

use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Comment;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Support\HiddenRouteModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Round 3 R3-081, R3-082, R2R-07 (the rule of round 2a R2A-062 for the other routes that name something by its number or
 * its link, App\Support\HiddenRouteModels): another member's playlist, history row, report, invoice, studio comment,
 * notification and device, a moderator-hidden comment, a suspended or unconfirmed member, and another member's private
 * video or playlist by its link answer exactly like an id or a link that does not exist - the same status, body and
 * headers (they answered 403, "This video is private", "This playlist is private", "You can't subscribe to this user",
 * or the same status with the headers of the middleware that only run once a model is found). Who may see the model
 * goes on as before; a removed video and a suspended channel keep their 410 notices.
 */
class HiddenRouteModelsAnswerLikeMissingTest extends TestCase
{
    use RefreshDatabase;

    private const MISSING_UUID = '00000000-0000-4000-8000-000000000000';

    private User $owner;

    private User $member;

    private User $third;

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.default_locale' => 'en', 'cache.limiter' => 'array', 'site.email_verification_required' => true]);
        Cache::flush();
        $this->owner = User::factory()->create(['email_verified_at' => now()]);
        $this->member = User::factory()->create(['email_verified_at' => now()]);
        $this->third = User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_every_route_of_the_list_exists_and_names_its_parameters(): void
    {
        foreach (HiddenRouteModels::ROUTES as $name => $rules) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name.' is a route');
            foreach (array_keys($rules) as $parameter) {
                $this->assertContains($parameter, $route->parameterNames(), $name.' has {'.$parameter.'}');
            }
        }
    }

    public function test_another_members_rows_answer_like_missing_ones(): void
    {
        $video = $this->video(VideoStatus::PUBLIC);
        $rows = $this->rowsOf($this->owner, $video);
        $demo = User::factory()->create(['email_verified_at' => now()]);
        $demo->forceFill(['is_view_only' => true])->save();

        foreach (['a member' => $this->member, 'the view-only demo account' => $demo] as $who => $asker) {
            foreach ([
                ['GET', 'user.playlists.edit', ['playlist' => $rows['private']], false],
                ['GET', 'user.playlists.edit', ['playlist' => $rows['public']], false],
                ['GET', 'user.playlists.edit', ['playlist' => $rows['watch later']], false],
                ['PUT', 'user.playlists.update', ['playlist' => $rows['unlisted']], false],
                ['DELETE', 'user.playlists.destroy', ['playlist' => $rows['public']], false],
                ['POST', 'user.playlists.favorite', ['playlist' => $rows['private']], false],
                ['POST', 'user.playlists.favorite', ['playlist' => $rows['watch later']], false],
                ['POST', 'user.playlists.favorite', ['playlist' => $rows['unlisted']], false],
                ['DELETE', 'playlists.delete', ['playlist' => $rows['public']], true],
                ['DELETE', 'me.clear', ['view' => $rows['view']], true],
                ['POST', 'user.reports.cancel', ['report' => $rows['report']], false],
                ['GET', 'user.invoices.show', ['transaction' => $rows['transaction']], false],
                ['GET', 'user.comments.replies', ['comment' => $rows['comment']], false],
                ['DELETE', 'user.comments.destroy', ['comment' => $rows['comment']], false],
                ['GET', 'user.notifications.click', ['notification' => $rows['notification']], false],
                ['POST', 'notifications.read', ['notification' => $rows['notification']], true],
                ['POST', 'notifications.unread', ['notification' => $rows['notification']], true],
                ['DELETE', 'notifications.remove', ['notification' => $rows['notification']], true],
                ['POST', 'devices.update', ['device' => $rows['device']], true],
                ['DELETE', 'devices.delete', ['device' => $rows['device']], true],
                ['PUT', 'comments.update', ['video' => $video, 'comment' => $rows['hidden comment']], true],
                ['DELETE', 'comments.delete', ['video' => $video, 'comment' => $rows['hidden comment']], true],
                ['POST', 'comments.pin', ['video' => $video, 'comment' => $rows['hidden comment']], true],
                ['POST', 'comments.unpin', ['video' => $video, 'comment' => $rows['hidden comment']], true],
            ] as [$method, $name, $parameters, $api]) {
                $this->assertLikeMissing($asker, $method, $name, $parameters, $api, $who);
            }
        }

        // nothing of the owner's changed
        $this->assertSame(4, DB::table('playlists')->where('user_id', $this->owner->id)->count());
        $this->assertSame(1, DB::table('views')->where('user_id', $this->owner->id)->count());
        $this->assertSame(1, DB::table('reports')->where('user_id', $this->owner->id)->count());
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $this->owner->id)->where('name', 'old phone')->count());
        $this->assertNull(DB::table('notifications')->where('id', $rows['notification'])->value('read_at'));
        $this->assertSame(0, DB::table('favorites_playlist')->count());
        $this->assertNotNull(DB::table('comments')->where('id', $rows['hidden comment']->id)->value('id'));
    }

    public function test_another_members_private_video_or_playlist_by_its_link_answers_like_a_made_up_link(): void
    {
        $hidden = [
            'private' => $this->video(VideoStatus::PRIVATE),
            'draft' => $this->video(VideoStatus::DRAFT, ['uploaded_at' => null]),
            'planned' => $this->video(VideoStatus::PLANNED, ['scheduled_at' => now()->addDays(3)]),
            'still uploading' => $this->video(VideoStatus::PUBLIC, ['uploaded_at' => null]),
        ];
        $public = $this->video(VideoStatus::PUBLIC);
        $hiddenComment = $this->comment($public, $this->third, ['banned_at' => now()]);
        $privatePlaylist = Playlist::factory()->create(['user_id' => $this->owner->id, 'status' => PlaylistStatus::PRIVATE]);

        foreach (['a guest' => null, 'a member' => $this->member] as $who => $asker) {
            foreach ($hidden as $kind => $video) {
                $comment = $this->comment($video, $this->owner);
                foreach ([
                    ['GET', 'videos.show', ['video' => $video], true],
                    ['GET', 'videos.player', ['video' => $video], true],
                    ['POST', 'videos.view', ['video' => $video], true],
                    ['GET', 'comments.index', ['video' => $video], true],
                    ['GET', 'comments.replies', ['video' => $video, 'comment' => $comment], true],
                    ['GET', 'video.thumbnail', ['video' => $video], false],
                    ['GET', 'video.file', ['video' => $video], false],
                ] as [$method, $name, $parameters, $api]) {
                    $this->assertLikeMissing($asker, $method, $name, $parameters, $api, $who.', '.$kind);
                }
                if ($asker !== null) {
                    $this->assertLikeMissing($asker, 'POST', 'videos.progress', ['video' => $video], true, $who.', '.$kind);
                    $this->assertLikeMissing($asker, 'POST', 'comments.store', ['video' => $video], true, $who.', '.$kind);
                }
            }
            $this->assertLikeMissing($asker, 'GET', 'comments.replies', ['video' => $public, 'comment' => $hiddenComment], true, $who.', a hidden comment');
            foreach ([['GET', 'playlist.show', false], ['GET', 'playlists.show', true], ['GET', 'playlists.videos', true]] as [$method, $name, $api]) {
                $this->assertLikeMissing($asker, $method, $name, ['playlist' => $privatePlaylist], $api, $who.', a private playlist');
            }
        }
        $this->assertSame(0, DB::table('views')->count(), 'no view recorded');
        $this->assertSame(0, DB::table('comments')->where('content', 'x')->count(), 'no comment posted');
    }

    public function test_a_suspended_or_unconfirmed_member_answers_like_a_missing_one(): void
    {
        $suspended = User::factory()->create(['email_verified_at' => now(), 'banned_at' => now()]);
        $unconfirmed = User::factory()->create(['email_verified_at' => null]);

        foreach (['suspended' => $suspended, 'unconfirmed' => $unconfirmed] as $kind => $user) {
            foreach ([
                ['POST', 'subscribe', true],
                ['GET', 'notifications.channel', true],
                ['POST', 'notifications.channel.update', true],
            ] as [$method, $name, $api]) {
                $this->assertLikeMissing($this->member, $method, $name, ['user' => $user], $api, $kind);
            }
            foreach (['user.avatar', 'user.banner'] as $name) {
                $this->assertLikeMissing(null, 'GET', $name, ['user' => $user], false, 'a guest, '.$kind);
                $this->assertLikeMissing($this->member, 'GET', $name, ['user' => $user], false, 'a member, '.$kind);
            }
        }

        // an unconfirmed member's channel is missing; a suspended one keeps the channel's own 410 notice (by design)
        foreach ([['GET', 'user.show', false], ['GET', 'users.show', true], ['GET', 'users.videos', true], ['GET', 'users.playlists', true]] as [$method, $name, $api]) {
            $this->assertLikeMissing(null, $method, $name, ['user' => $unconfirmed], $api, 'a guest, unconfirmed');
        }
        $this->get(route('user.show', $suspended, false))->assertStatus(410);
        $this->getJson(route('users.show', $suspended, false))->assertStatus(410);
        $this->assertSame(0, DB::table('subscriptions')->count());
    }

    public function test_signing_in_on_the_way_does_the_same_for_a_missing_id(): void
    {
        // the member's "remember me" cookie on a picture route (no session): the hidden id signed the member in again
        // (a new session cookie, Cache-Control: no-store), the missing one never looked the member up
        $this->member->setRememberToken(Str::random(60));
        $this->member->save();
        $recaller = $this->member->getAuthIdentifier().'|'.$this->member->getRememberToken().'|'.$this->member->getAuthPassword();
        $recallerName = $this->app['auth']->guard('web')->getRecallerName();
        $unconfirmed = User::factory()->create(['email_verified_at' => null]);
        $private = $this->video(VideoStatus::PRIVATE);

        foreach ([['user.avatar', ['user' => $unconfirmed]], ['video.thumbnail', ['video' => $private]]] as [$name, $parameters]) {
            $answers = [];
            foreach (['hidden' => $parameters, 'missing' => $this->missing($name, $parameters)] as $which => $values) {
                $this->guestAgain();
                $answers[$which] = $this->withCookie($recallerName, $recaller)->getJson(route($name, $values, false));
            }
            $this->assertSame(404, $answers['hidden']->getStatusCode(), $name);
            $this->assertSame($this->cookieNames($answers['missing']), $this->cookieNames($answers['hidden']), $name.': the same cookies');
            $this->assertSame($this->headers($answers['missing']), $this->headers($answers['hidden']), $name.': the same headers');
            $this->assertSame($this->body($answers['missing'], $this->missing($name, $parameters)), $this->body($answers['hidden'], $this->routeValues($name, $parameters)), $name.': body');
        }
        $this->guestAgain();
        $this->flushHeaders();
        $this->defaultCookies = [];

        // the apps' token on their public routes: it is marked as used for a missing link as for a hidden one
        $token = $this->member->createToken('phone', ['api']);
        $used = [];
        foreach (['hidden' => (string) $private->uuid, 'missing' => '9999999999'] as $which => $uuid) {
            DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update(['last_used_at' => null]);
            $this->guestAgain();
            $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)->getJson('/api/videos/'.$uuid)->assertNotFound();
            $this->flushHeaders();
            $used[$which] = DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->value('last_used_at') !== null;
        }
        $this->assertSame($used['missing'], $used['hidden'], 'the token\'s last use is written alike');

        // the watch page asked for JSON: another member's private video like a made-up link
        $this->guestAgain();
        $hidden = $this->getJson(parse_url($private->route, PHP_URL_PATH));
        $this->guestAgain();
        $made = $this->getJson('/video/'.Str::slug($private->title).'-9999999999');
        $this->assertSame(404, $hidden->getStatusCode());
        $this->assertSame($made->getStatusCode(), $hidden->getStatusCode());
        $this->assertSame($made->json(), $hidden->json());
        $this->assertSame($this->headers($made), $this->headers($hidden));
    }

    public function test_whoever_may_see_it_goes_on_as_before(): void
    {
        $video = $this->video(VideoStatus::PUBLIC);
        $rows = $this->rowsOf($this->owner, $video);
        $private = $this->video(VideoStatus::PRIVATE);
        $privateComment = $this->comment($private, $this->owner);
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

        // the owner of the rows
        $this->actingAs($this->owner)->get(route('user.playlists.edit', $rows['private'], false))->assertOk();
        $this->actingAs($this->owner)->get(route('user.playlists.edit', $rows['watch later'], false))->assertForbidden(); // its own refusal
        $this->actingAs($this->owner)->get(route('user.invoices.show', $rows['transaction'], false))->assertOk();
        $this->actingAs($this->owner)->post(route('user.reports.cancel', $rows['report'], false))->assertRedirect();
        $this->actingAs($this->owner, 'sanctum')->postJson(route('notifications.read', $rows['notification'], false))->assertOk();
        $this->actingAs($this->owner, 'sanctum')->deleteJson(route('me.clear', $rows['view'], false))->assertSuccessful();
        // an administrator sees every invoice (InvoicePolicy)
        $this->guestAgain();
        $this->actingAs($admin)->get(route('user.invoices.show', $rows['transaction'], false))->assertOk();

        // the channel sees its private video by its link, its picture and its comments; an administrator too
        $this->actingAs($this->owner, 'sanctum')->getJson(route('videos.show', $private, false))->assertOk();
        $this->actingAs($this->owner, 'sanctum')->getJson(route('comments.replies', [$private, $privateComment], false))->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson(route('videos.show', $private, false))->assertOk();
        $this->guestAgain();
        $this->actingAs($this->owner)->get(route('video.thumbnail', $private, false))->assertOk();
        // ... and with the apps' token on the public routes (it is signed in only after the binding: OptionalAuthSanctum)
        $this->guestAgain();
        $token = $this->owner->createToken('phone', ['api'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson(route('videos.show', $private, false))->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson(route('videos.player', $private, false))->assertOk();
        $this->flushHeaders();

        // a member: a public video, another member's public playlist (saved and taken out again), an active channel
        $this->guestAgain();
        $this->actingAs($this->member, 'sanctum')->getJson(route('videos.show', $video, false))->assertOk();
        $this->guestAgain();
        $this->actingAs($this->member)->postJson(route('user.playlists.favorite', $rows['public'], false))->assertNoContent();
        $this->actingAs($this->member)->postJson(route('user.playlists.favorite', $rows['public'], false))->assertNoContent();
        $this->assertSame(0, DB::table('favorites_playlist')->count());
        $this->actingAs($this->member, 'sanctum')->postJson(route('subscribe', $this->owner, false))->assertSuccessful();
        // a visible comment of another member keeps its own refusal (it is public: the 403 tells nothing new)
        $visible = $this->comment($video, $this->owner);
        $this->actingAs($this->member, 'sanctum')->deleteJson(route('comments.delete', [$video, $visible], false))->assertForbidden();
        // a removed video keeps its 410 notice, as on its watch page
        $this->guestAgain();
        $banned = $this->video(VideoStatus::BANNED, ['banned_at' => now()]);
        $this->getJson(route('videos.show', $banned, false))->assertStatus(410);
    }

    private function assertLikeMissing(?User $asker, string $method, string $name, array $parameters, bool $api, string $context): void
    {
        $missing = $this->missing($name, $parameters);
        $hiddenAnswer = $this->ask($asker, $method, $name, $parameters, $api);
        $missingAnswer = $this->ask($asker, $method, $name, $missing, $api);
        $context .= ': '.$method.' '.$name;

        $this->assertSame(404, $hiddenAnswer->getStatusCode(), $context.': not found');
        $this->assertSame($missingAnswer->getStatusCode(), $hiddenAnswer->getStatusCode(), $context.': status');
        $this->assertSame($this->body($missingAnswer, $missing), $this->body($hiddenAnswer, $this->routeValues($name, $parameters)), $context.': body');
        $this->assertSame($this->headers($missingAnswer), $this->headers($hiddenAnswer), $context.': headers');
    }

    private function ask(?User $asker, string $method, string $name, array $parameters, bool $api): TestResponse
    {
        // every pair starts from the same limiter state and a new session
        Cache::store('array')->flush();
        $this->guestAgain();
        $url = route($name, $parameters, false);
        $body = in_array($method, ['POST', 'PUT', 'DELETE'], true) ? ['title' => 'x', 'status' => 0, 'sort' => 0, 'content' => 'x', 'name' => 'x', 'seconds' => 3] : [];

        if ($asker === null) {
            return $api ? $this->json($method, $url, $body) : $this->call($method, $url, $body, [], [], ['HTTP_ACCEPT' => 'text/html']);
        }

        return $api
            ? $this->actingAs($asker, 'sanctum')->json($method, $url, $body)
            : $this->actingAs($asker)->call($method, $url, $body, [], [], ['HTTP_ACCEPT' => 'text/html']);
    }

    /** A guest again: no signed-in member, the site's default guard, a new session. */
    private function guestAgain(): void
    {
        $this->app['auth']->forgetGuards();
        config(['auth.defaults.guard' => 'web']);
        $this->flushSession();
    }

    /** @return array<string, string> the parameters' values in the route's address */
    private function routeValues(string $name, array $parameters): array
    {
        $route = Route::getRoutes()->getByName($name);
        $values = [];
        foreach ($parameters as $key => $value) {
            $field = $route?->bindingFieldFor($key);
            $values[$key] = $value instanceof Model ? (string) ($field !== null ? $value->getAttribute($field) : $value->getRouteKey()) : (string) $value;
        }

        return $values;
    }

    /** @return array<string, string> the same parameters, each naming something that does not exist (same kind of key) */
    private function missing(string $name, array $parameters): array
    {
        $route = Route::getRoutes()->getByName($name);
        $missing = [];
        foreach ($parameters as $key => $value) {
            $field = $route?->bindingFieldFor($key);
            $missing[$key] = match (true) {
                $field === 'file' => 'nothing-here.mp4',
                $field === 'uuid' && $value instanceof Video => '9999999999',
                $field === 'uuid' => self::MISSING_UUID,
                $value instanceof Model => (string) ($value::query()->max($value->getKeyName()) + 100000),
                is_string($value) => (string) Str::uuid(),
                default => (string) ((int) $value + 100000),
            };
        }

        return $missing;
    }

    /** The body with the address's values of the parameters as <ID> (each only where an address holds it). */
    private function body(TestResponse $response, array $values): string
    {
        $body = (string) $response->getContent();
        foreach ($values as $value) {
            $body = (string) preg_replace('#(/|/@|%2F|%2F%40|\\\\/|\\\\/@)'.preg_quote($value, '#').'(?=[/?"&%\s\\\\<]|$)#', '$1<ID>', $body);
        }

        return (string) preg_replace(['/name="csrf-token" content="[^"]+"/', '/name="_token" value="[^"]+"/', '/nonce="[^"]+"/'], ['csrf', '_token', 'nonce'], $body);
    }

    /** @return list<string> the names of the cookies the answer sets (their values differ by nature) */
    private function cookieNames(TestResponse $response): array
    {
        $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
        sort($names);

        return $names;
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

    private function video(VideoStatus $status, array $extra = []): Video
    {
        return Video::factory()->for($this->owner)->withStatus($status)->create(array_merge([
            'published_at' => now()->subDay(), 'uploaded_at' => now()->subDay(), 'allow_comments' => true, 'file' => Str::random(12).'.mp4',
        ], $extra));
    }

    private function comment(Video $video, User $author, array $extra = []): Comment
    {
        return Comment::withoutEvents(fn () => Comment::factory()->create(array_merge(['video_id' => $video->id, 'user_id' => $author->id], $extra)));
    }

    /** @return array<string, mixed> the owner's rows of every kind */
    private function rowsOf(User $owner, Video $video): array
    {
        $playlist = fn (PlaylistStatus $status, bool $deletable = true, string $title = '') => Playlist::factory()->create([
            'user_id' => $owner->id, 'status' => $status, 'is_deletable' => $deletable, 'title' => $title !== '' ? $title : 'List '.Str::random(6),
        ]);
        $view = DB::table('views')->insertGetId(['video_id' => $video->id, 'user_id' => $owner->id, 'ip' => '127.0.0.1', 'view_at' => now()]);
        $report = DB::table('reports')->insertGetId(['user_id' => $owner->id, 'reportable_type' => Video::class, 'reportable_id' => $video->id, 'reason' => 1, 'status' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $transaction = DB::table('transactions')->insertGetId(['payment_provider' => 'stripe', 'stripe_id' => 'tx-'.Str::random(8), 'amount' => 100, 'tax' => 0, 'fee' => 0, 'date' => now(), 'user_id' => $owner->id, 'name' => 'x', 'address' => 'x', 'city' => 'x', 'postal_code' => 'x', 'country' => 'FR']);
        $notification = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $notification, 'type' => 'test', 'notifiable_type' => $owner->getMorphClass(), 'notifiable_id' => $owner->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $device = DB::table('personal_access_tokens')->insertGetId(['tokenable_type' => $owner->getMorphClass(), 'tokenable_id' => $owner->id, 'name' => 'old phone', 'token' => hash('sha256', Str::random(40)), 'abilities' => '["api"]', 'created_at' => now(), 'updated_at' => now()]);

        return [
            'private' => $playlist(PlaylistStatus::PRIVATE),
            'public' => $playlist(PlaylistStatus::PUBLIC),
            'unlisted' => $playlist(PlaylistStatus::UNLISTED),
            'watch later' => $playlist(PlaylistStatus::PRIVATE, false, 'Watch Later'),
            'view' => $view,
            'report' => $report,
            'transaction' => $transaction,
            'notification' => $notification,
            'device' => $device,
            // on the owner's video: one by the member (the studio's comment routes belong to the owner), one hidden
            'comment' => $this->comment($video, $this->member),
            'hidden comment' => $this->comment($video, $this->third, ['banned_at' => now()]),
        ];
    }
}
