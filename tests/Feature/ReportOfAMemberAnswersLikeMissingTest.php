<?php

namespace Tests\Feature;

use App\Enums\ReportReason;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Round 3 R3-082: the report of a member (POST /api/report, type User) answered 403 "You are not authorized to report this
 * user" (UserPolicy::report) for a suspended member - and, while the site asks members to confirm their address, for one
 * who has not - against 422 "This channel can no longer be reported." for a missing id: whether such an account exists
 * could be read. Now they get exactly the missing id's answer, with the same work; the member's own channel and one the
 * member reported already keep the policy's refusal (the member knows those exist), an active member is reported as
 * before. Nothing is reported either way.
 */
class ReportOfAMemberAnswersLikeMissingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.default_locale' => 'en', 'cache.limiter' => 'array', 'site.email_verification_required' => true]);
        Cache::flush();
    }

    public function test_a_suspended_or_unconfirmed_member_is_answered_like_a_missing_one(): void
    {
        $member = User::factory()->create(['email_verified_at' => now()]);
        $suspended = User::factory()->create(['email_verified_at' => now(), 'banned_at' => now()]);
        $unconfirmed = User::factory()->create(['email_verified_at' => null]);
        $missing = (int) User::withTrashed()->max('id') + 1000;
        $missingAnswer = $this->report($member, $missing);

        foreach (['suspended' => $suspended, 'unconfirmed' => $unconfirmed] as $kind => $user) {
            $answer = $this->report($member, $user->id);
            $this->assertSame(422, $answer->getStatusCode(), $kind);
            $this->assertSame($missingAnswer->json(), $answer->json(), $kind.': the missing id\'s answer');
            $this->assertSame(['id' => [__('This channel can no longer be reported.')]], $answer->json('errors'), $kind);
            $this->assertSame($this->headers($missingAnswer), $this->headers($answer), $kind.': headers');
        }

        // in another language too
        $this->assertSame(__('This channel can no longer be reported.', [], 'fr'), $this->report($member, $suspended->id, 'fr')->json('errors.id.0'));
        $this->assertSame(0, Report::query()->count());

        // without e-mail confirmation an unconfirmed member is an active one (as on the channel page)
        config(['site.email_verification_required' => false]);
        $this->report($member, $unconfirmed->id)->assertNoContent();
    }

    public function test_the_other_refusals_and_an_ordinary_report_stay_as_they_were(): void
    {
        $member = User::factory()->create(['email_verified_at' => now()]);
        $channel = User::factory()->create(['email_verified_at' => now()]);

        $this->report($member, $channel->id)->assertNoContent();
        $this->report($member, $channel->id)->assertForbidden()->assertJsonPath('message', __('You are not authorized to report this user'));
        $this->report($member, $member->id)->assertForbidden();
        $this->assertSame(1, DB::table('reports')->count());
    }

    public function test_the_same_queries_for_a_suspended_and_a_missing_member(): void
    {
        $member = User::factory()->create(['email_verified_at' => now()]);
        $suspended = User::factory()->create(['email_verified_at' => now(), 'banned_at' => now()]);
        $missing = (int) User::withTrashed()->max('id') + 1000;
        $this->countingQueries($member, $missing + 1);

        [$hidden, $hiddenQueries] = $this->countingQueries($member, $suspended->id);
        [$absent, $absentQueries] = $this->countingQueries($member, $missing);
        $this->assertSame($absent->json(), $hidden->json());
        $this->assertSame($absentQueries, $hiddenQueries);
    }

    private function report(User $member, int $id, ?string $locale = null): TestResponse
    {
        Cache::store('array')->flush();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($member, 'sanctum')->withHeaders($locale ? ['X-Locale' => $locale] : [])
            ->postJson('/api/report', ['reason' => ReportReason::SEXUAL_CONTENT->value, 'type' => User::class, 'id' => $id]);
    }

    private function countingQueries(User $member, int $id): array
    {
        Cache::store('array')->flush();
        $this->app['auth']->forgetGuards();
        $this->actingAs($member, 'sanctum');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->postJson('/api/report', ['reason' => ReportReason::SEXUAL_CONTENT->value, 'type' => User::class, 'id' => $id]);
        $queries = array_map(fn (array $q) => preg_replace('/\d+/', 'N', $q['query']), DB::getQueryLog());
        DB::disableQueryLog();

        return [$response, $queries];
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
