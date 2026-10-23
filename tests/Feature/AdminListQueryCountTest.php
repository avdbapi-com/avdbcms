<?php

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Language;
use App\Models\Plan;
use App\Models\Playlist;
use App\Models\Report;
use App\Models\User;
use App\Models\Video;
use App\Support\AdminPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The list pages read a page of rows with a fixed number of queries (eager loading, cached counts): adding rows never
 * adds queries (no N+1), and a page stays within the list budget (<= 10 queries for its own data, cold caches).
 */
class AdminListQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private const BUDGET = 10;

    public static function lists(): array
    {
        return [
            'videos' => ['admin.videos.index'],
            'users' => ['admin.users.index'],
            'comments' => ['admin.comments.index'],
            'reports' => ['admin.reports.index'],
            'playlists' => ['admin.playlists.index'],
            'categories' => ['admin.categories.index'],
            'tags' => ['admin.tags.index'],
            'languages' => ['admin.languages.index'],
            'pages' => ['admin.pages.index'],
            'plans' => ['admin.plans.index'],
            'inbox' => ['admin.contacts.index'],
            'redirects' => ['admin.redirects.index'],
            'blocked addresses' => ['admin.ip-bans.index'],
            'announcements' => ['admin.announcements.index'],
            'email not confirmed' => ['admin.verification.index'],
            // Wave 3 (ADMB): the other list pages.
            'signed in now' => ['admin.sessions.index'],
            'devices' => ['admin.devices.index'],
            'password resets' => ['admin.password-resets.index'],
            'activity' => ['admin.activities.index'],
            'notifications sent' => ['admin.notifications.index'],
            'notification to everyone' => ['admin.broadcast.index'],
            'homepage picks' => ['admin.featured.index'],
            'views' => ['admin.views.index'],
            'searches' => ['admin.searches.index'],
            'likes' => ['admin.interactions.index'],
            'files' => ['admin.media.index'],
            'thumbnails' => ['admin.thumbnails.index'],
            'exports' => ['admin.exports.index'],
            'spam filter' => ['admin.spams.index'],
            'subscriptions' => ['admin.subscriptions.index'],
            'premium records' => ['admin.premiums.index'],
            'payments' => ['admin.transactions.index'],
            'invoices' => ['admin.invoices.index'],
            'failed jobs' => ['admin.failed-jobs.index'],
            'admin actions' => ['admin.audit.index'],
        ];
    }

    #[DataProvider('lists')]
    public function test_a_list_page_reads_its_rows_with_a_fixed_number_of_queries(string $route): void
    {
        config(['admin.ui' => 'v2']);
        $admin = $this->admin();
        $this->seedRows(3);
        $few = $this->queries($admin, $route);

        $this->seedRows(12);
        $many = $this->queries($admin, $route);

        $this->assertLessThanOrEqual($few, $many, "{$route}: ".($many - $few).' more queries for more rows (N+1)');
        $this->assertLessThanOrEqual(self::BUDGET, $this->ownQueries($admin, $route), "{$route} reads its page in at most ".self::BUDGET.' queries');
    }

    /**
     * The old admin (one release) renders the same rows from the same controllers. Its views still show member
     * avatars, and User::avatar_url reads each member's row again (AvdbCreatorAvatarFactory), so it is not held
     * to the budget; the controllers' eager loading still serves it.
     */
    #[DataProvider('lists')]
    public function test_the_old_admin_still_renders_the_lists(string $route): void
    {
        config(['admin.ui' => 'v1']);
        $admin = $this->admin();
        $this->seedRows(3);

        $this->actingAs($admin)->get(route($route))->assertOk();
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', true);

        return $admin;
    }

    /** Every query of one page view with cold caches (the page's own cached counts and lists are counted too). */
    private function queries(User $admin, string $route): int
    {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route($route))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * The queries of the page's own list (the shell's: session, user, settings, navigation badges are the same on every
     * admin page and cached): the page minus an empty admin page of the same shell.
     */
    private function ownQueries(User $admin, string $route): int
    {
        return $this->queries($admin, $route) - $this->queries($admin, 'admin.shell.moved');
    }

    private function seedRows(int $n): void
    {
        $category = Category::query()->firstOrCreate(['slug' => 'query-count'], ['title' => 'Query count']);

        for ($i = 0; $i < $n; $i++) {
            $owner = User::factory()->create();
            $video = Video::factory()->for($owner)->create(['category_id' => $category->id]);
            $comment = Comment::query()->create(['video_id' => $video->id, 'user_id' => $owner->id, 'content' => 'Nice video '.$i, 'ip' => '127.0.0.1']);
            Comment::query()->create(['video_id' => $video->id, 'user_id' => $owner->id, 'parent_id' => $comment->id, 'content' => 'A reply '.$i, 'ip' => '127.0.0.1']);
            Report::query()->create(['user_id' => $owner->id, 'reportable_type' => Video::class, 'reportable_id' => $video->id, 'reason' => 1, 'status' => ReportStatus::PENDING]);
            Report::query()->create(['user_id' => $owner->id, 'reportable_type' => Comment::class, 'reportable_id' => $comment->id, 'reason' => 1, 'status' => ReportStatus::PENDING]);
            $playlist = Playlist::factory()->for($owner)->create();
            DB::table('playlist_has_videos')->insert(['playlist_id' => $playlist->id, 'video_id' => $video->id, 'position' => 1, 'added_at' => now()]);
            DB::table('tags')->insert(['name' => 'Tag '.$i.'-'.$video->id, 'slug' => 'tag-'.$i.'-'.$video->id, 'created_at' => now(), 'updated_at' => now()]);
            Category::query()->create(['title' => 'Category '.$video->id, 'slug' => 'category-'.$video->id, 'in_menu' => $i % 2 === 0]);
            Plan::query()->create(['name' => 'Plan '.$video->id, 'price' => 100 + $i, 'duration' => 1]);
            DB::table('custom_pages')->insert(['title' => 'Page '.$video->id, 'slug' => 'page-'.$video->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('contact_messages')->insert(['name' => 'Visitor '.$i, 'email' => 'v'.$video->id.'@example.test', 'message' => 'Hello', 'is_read' => $i % 2, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('redirects')->insert(['source' => '/old-'.$video->id, 'destination' => '/new', 'status_code' => 301, 'is_active' => true, 'hits' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ip_bans')->insert(['ip_address' => '203.0.113.'.($video->id % 250), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('announcements')->insert(['title' => 'News '.$video->id, 'message' => 'Short.', 'type' => 'info', 'position' => 'top', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            Language::query()->firstOrCreate(['code' => 'l'.chr(97 + $i % 26).chr(97 + intdiv($video->id, 26) % 26)], ['label' => 'Language '.$video->id, 'emoji' => 'XX', 'direction' => 'ltr', 'is_active' => true, 'is_default' => false, 'sort_order' => $i]);
        }

        // The tables of the wave-3 lists (devices, notifications, payments, failed jobs ...).
        AdminPageSmokeTest::seedOtherLists(User::factory()->admin()->create(), $n, 'q'.$n.'-');
    }
}
