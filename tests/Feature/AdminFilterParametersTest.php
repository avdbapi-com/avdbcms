<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Filters\UserFilters;
use App\Filters\VideoFilters;
use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use App\Http\Middleware\IsAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R1-075: the lists' filters (App\Filters\*) took any query key as a method name. Private helpers ("?day=x",
 * "?list_date_from=1"), the driver's own apply() ("?apply=1") and a list value for a text filter ("?search[]=x")
 * answered 500 with a stack trace in the log. Only the public filters a class declares are filters now, and only with
 * a value that fits them: a crafted address lists as usual, and the real filters still work.
 */
class AdminFilterParametersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2']);
    }

    /** @return array<string, array{0: string}> */
    public static function craftedAddresses(): array
    {
        $rows = [];

        foreach (['/admin/videos', '/admin/users', '/admin/comments', '/admin/reports', '/admin/playlists', '/admin/activities',
                     '/admin/categories', '/admin/tags', '/admin/invoice'] as $list) {
            foreach (['apply=1', 'day=x', 'list_date_from=1', 'received_filters=1', 'filter_method=1', '__construct=1',
                         'search[]=x', 'status[]=1', 'category[]=1', 'user[]=1', 'video[]=1', 'date_start[]=x', 'type[]=x',
                         'unpublished[]=1', 'replies[]=with', 'ban[]=banned', 'reason[]=x', 'date_start=not-a-date'] as $query) {
                $rows[$list.'?'.$query] = [$list.'?'.$query];
            }
        }

        return $rows;
    }

    #[DataProvider('craftedAddresses')]
    public function test_a_crafted_filter_parameter_never_answers_500(string $address): void
    {
        $this->actingAs(User::factory()->admin()->create())->get($address)->assertOk();
    }

    /**
     * A list where a tool reads one value: the tags' usage filter, the media folder, the failed jobs' "show", the log
     * viewer's file (its page and its own API) answered 500 ("Array to string conversion", basename() given an array).
     * The log viewer's own list keys (exclude_levels[], exclude_file_types[]) given one value or a list of lists too.
     *
     * @return array<string, array{0: string}>
     */
    public static function craftedToolAddresses(): array
    {
        $rows = [];

        foreach (['/admin/tags?usage[]=x', '/admin/tags?usage[a]=x', '/admin/media?path[]=x', '/admin/media?path[a][]=x',
                     '/admin/failed-jobs?show[]=x', '/admin/logs-viewer?file[]=x', '/admin/logs/api/logs?file[]=x',
                     '/admin/logs/api/logs?query[]=x', '/admin/logs/api/logs?direction[]=asc', '/admin/logs/api/files?direction[]=asc',
                     '/admin/logs/api/folders?direction[]=asc', '/admin/logs/api/logs?query=x&exclude_file_types=laravel',
                     '/admin/logs/api/logs?query=x&exclude_file_types[a][]=x&exclude_levels[a][b]=x',
                     '/admin/audit?action[]=x', '/admin/searches?search[]=x&period[]=7', '/admin/sessions?who[]=x'] as $address) {
            $rows[$address] = [$address];
        }

        return $rows;
    }

    #[DataProvider('craftedToolAddresses')]
    public function test_a_crafted_list_for_a_tool_never_answers_500(string $address): void
    {
        config(['admin.operational_tools_enabled' => true]);

        $this->actingAs(User::factory()->admin()->create())->get($address)->assertOk();
    }

    public function test_an_admin_get_keeps_lists_only_for_the_log_viewers_list_keys(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // What a page reads after App\Http\Middleware\IsAdmin (the 'admin' middleware of every admin route).
        $seen = static function (Request $request): array {
            $input = null;
            (new IsAdmin())->handle($request, static function (Request $next) use (&$input) {
                $input = $next->all();

                return response('ok');
            });

            return $input;
        };

        $this->assertSame([
            'page' => '2',
            'q' => 'video',
            'exclude_levels' => ['debug', 'info'],
            'exclude_file_types' => ['laravel'],
        ], $seen(Request::create('/admin/logs/api/logs?search[]=x&usage[a]=1&path[a][]=x&page=2&q=video'
            .'&exclude_levels[]=debug&exclude_levels[]=info&exclude_file_types=laravel&0[]=x')));

        // A list of lists is no list of levels; HEAD is a GET.
        $this->assertSame(['tab' => 'all'], $seen(Request::create('/admin/logs?exclude_levels[a][b]=x&tab=all', 'HEAD')));

        // A form that posts a list keeps it (the bulk bars' ids[]): only the query of a GET is shaped.
        $this->assertSame(['ids' => ['1', '2'], 'action' => 'ban'], $seen(Request::create('/admin/bulk/videos', 'POST', ['ids' => ['1', '2'], 'action' => 'ban'])));
    }

    public function test_the_real_filters_still_narrow_the_lists(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create(['username' => 'filtercreator']);
        Video::factory()->for($creator)->create(['title' => 'Harbour at night', 'status' => VideoStatus::PUBLIC->value]);
        Video::factory()->for($creator)->create(['title' => 'Mountain morning', 'status' => VideoStatus::PRIVATE->value]);

        $this->actingAs($admin)->get('/admin/videos?search=Harbour')->assertOk()
            ->assertSee('Harbour at night')->assertDontSee('Mountain morning');
        $this->actingAs($admin)->get('/admin/videos?status='.VideoStatus::PRIVATE->value)->assertOk()
            ->assertSee('Mountain morning')->assertDontSee('Harbour at night');
        $this->actingAs($admin)->get('/admin/users?search=filtercreator')->assertOk()->assertSee('filtercreator');
    }

    public function test_only_the_public_filters_of_a_class_are_called(): void
    {
        Video::factory()->for(User::factory())->create(['title' => 'Kept']);

        // Unknown keys, the driver's own methods, private helpers and list values are left alone.
        $query = (new VideoFilters([
            'apply' => '1', 'receivedFilters' => '1', 'day' => 'x', 'list_date_from' => '1', 'listDateFrom' => '1',
            'search' => ['x'], 'status' => ['1'], 'filterMethod' => 'search', '' => 'x', 7 => 'x',
        ]))->apply(Video::query());

        $this->assertSame(1, $query->count());
        $this->assertSame([], $query->getQuery()->wheres, 'no condition was added');

        // A text filter with text still filters.
        $this->assertSame(0, (new VideoFilters(['search' => 'nothing-like-this']))->apply(Video::query())->count());
        $this->assertSame(1, (new UserFilters(['search' => (string) User::query()->value('username')]))->apply(User::query())->count());

        // The comment filters of the member pages keep working with their own keys.
        $this->assertSame(0, Comment::filter(['__none' => '1'])->count());
    }
}
