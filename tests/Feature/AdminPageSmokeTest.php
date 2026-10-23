<?php

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Jobs\RunAvdbImport;
use App\Jobs\SyncAvdbDurationBatch;
use App\Models\AvdbImportRun;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Language;
use App\Models\Plan;
use App\Models\Report;
use App\Models\Thumbnail;
use App\Models\Tag;
use App\Models\User;
use App\Models\Video;
use App\Support\AdminPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPageSmokeTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('adminPages')]
    public function test_admin_pages_render_for_admin_users(string $routeName): void
    {
        $admin = User::factory()->admin()->create();
        // The developer pages (database, settings file, commands, API keys) need Developer mode.
        AdminPreferences::set($admin, 'developer_mode', true);

        $this->actingAs($admin)
            ->get(route($routeName))
            ->assertOk();
    }

    #[DataProvider('adminPages')]
    public function test_admin_pages_render_in_the_new_admin_with_one_h1(string $routeName): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', true);

        $html = $this->actingAs($admin)
            ->get(route($routeName))
            ->assertOk()
            ->getContent();

        // The shell prints the page's H1; old views' own title rows are H2 there.
        $this->assertSame(1, substr_count($html, '<h1'), "{$routeName} has one H1");
    }

    public function test_the_new_lists_page_25_rows_and_offer_50_and_100(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        Video::factory()->count(30)->for($admin)->create();

        $this->actingAs($admin)
            ->get(route('admin.videos.index'))
            ->assertOk()
            ->assertSee('Showing 1-25 of 30')
            ->assertSee('<option value="50"', false)
            ->assertSee('<option value="100"', false)
            // One menu template for the table; each row brings only its menu button (no menu box per row).
            ->assertSee('data-ad-row-menu="ad-vmenu"', false)
            ->assertSee('<template id="ad-vmenu">', false)
            ->assertDontSee('<div class="ad-menu" id="ad-vmenu-', false);

        // Old addresses keep working: a size the old admin offered still applies.
        $this->actingAs($admin)
            ->get(route('admin.videos.index', ['per_page' => 15, 'page' => 2]))
            ->assertOk()
            ->assertSee('Showing 16-30 of 30');
    }

    public function test_the_new_record_pages_render_with_one_h1_and_their_form(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $category = Category::query()->create(['title' => 'Music', 'slug' => 'music', 'in_menu' => true]);
        $plan = Plan::query()->create(['name' => 'Monthly', 'price' => 999, 'duration' => 1]);
        $language = Language::query()->create(['code' => 'zz', 'label' => 'Test language', 'emoji' => 'ZZ', 'direction' => 'ltr', 'is_active' => true, 'is_default' => false, 'sort_order' => 2]);
        $page = DB::table('custom_pages')->insertGetId(['title' => 'About us', 'slug' => 'about', 'content' => '<p>Hi</p>', 'is_published' => true, 'created_at' => now(), 'updated_at' => now()]);
        $message = DB::table('contact_messages')->insertGetId(['name' => 'Ann', 'email' => 'ann@example.test', 'subject' => 'Hello there', 'message' => 'Hi', 'is_read' => false, 'created_at' => now(), 'updated_at' => now()]);

        $pages = [
            // [address, the form's action (or a text of the page), saved with the save bar]
            [route('admin.users.edit', $member), route('admin.users.update', $member), true],
            [route('admin.users.show', $member), route('admin.users.update', $member), true],
            [route('admin.categories.create'), route('admin.categories.store'), false],
            [route('admin.categories.edit', $category), route('admin.categories.update', $category), true],
            [route('admin.plans.create'), route('admin.plans.store'), false],
            [route('admin.plans.edit', $plan), route('admin.plans.update', $plan), true],
            [route('admin.pages.create'), route('admin.pages.store'), false],
            [route('admin.pages.edit', $page), route('admin.pages.update', $page), true],
            [route('admin.languages.create'), route('admin.languages.store'), false],
            [route('admin.languages.edit-meta', $language), route('admin.languages.update-meta', $language), true],
        ];

        foreach ($pages as [$url, $action, $savebar]) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, '<h1'), "{$url} has one H1");
            $this->assertStringContainsString('class="ad-record-main"', $html, $url);
            $this->assertStringContainsString('action="'.$action.'"', $html, $url);
            $this->assertSame($savebar, str_contains($html, 'data-ad-savebar='), "{$url}: save bar for an existing record only");
            $this->assertStringNotContainsString('data-ad-legacy="on"', $html, "{$url} is a new page");
        }

        // Opening a message shows it and marks it read.
        $this->actingAs($admin)->get(route('admin.contacts.show', $message))->assertOk()->assertSee('Hello there')->assertSee('Reply by email');
        $this->assertSame(1, (int) DB::table('contact_messages')->where('id', $message)->value('is_read'));
    }

    public function test_the_new_lists_show_their_rows_with_one_menu_template(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        Category::query()->create(['title' => 'Music', 'slug' => 'music', 'in_menu' => true]);
        DB::table('tags')->insert(['name' => 'night city', 'slug' => 'night-city', 'usage_count' => 3, 'created_at' => now(), 'updated_at' => now()]);
        Language::query()->create(['code' => 'zz', 'label' => 'Test language', 'emoji' => 'ZZ', 'direction' => 'ltr', 'is_active' => true, 'is_default' => false, 'sort_order' => 2]);
        DB::table('custom_pages')->insert(['title' => 'About us', 'slug' => 'about', 'is_published' => true, 'created_at' => now(), 'updated_at' => now()]);
        Plan::query()->create(['name' => 'Yearly gold', 'price' => 9999, 'duration' => 12]);
        DB::table('contact_messages')->insert(['name' => 'Ann', 'email' => 'ann@example.test', 'subject' => 'Hello there', 'message' => 'Hi', 'is_read' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('redirects')->insert(['source' => '/old-page', 'destination' => '/new-page', 'status_code' => 301, 'is_active' => true, 'hits' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ip_bans')->insert(['ip_address' => '203.0.113.7', 'reason' => 'spam', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('announcements')->insert(['title' => 'Maintenance tonight', 'message' => 'Short.', 'type' => 'info', 'position' => 'top', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        User::factory()->create(['username' => 'waiting_member', 'email_verified_at' => null]);

        $lists = [
            'admin.categories.index' => ['ad-category-menu', 'Music'],
            'admin.tags.index' => ['ad-tag-menu', 'night city'],
            'admin.languages.index' => ['ad-language-menu', 'Test language'],
            'admin.pages.index' => ['ad-page-menu', 'About us'],
            'admin.plans.index' => ['ad-plan-menu', 'Yearly gold'],
            'admin.contacts.index' => ['ad-contact-menu', 'Hello there'],
            'admin.redirects.index' => ['ad-redirect-menu', '/old-page'],
            'admin.ip-bans.index' => ['ad-ban-menu', '203.0.113.7'],
            'admin.announcements.index' => ['ad-announcement-menu', 'Maintenance tonight'],
            'admin.verification.index' => ['ad-verify-menu', 'waiting_member'],
        ];

        foreach ($lists as $route => [$template, $row]) {
            $html = $this->actingAs($admin)->get(route($route))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, '<h1'), "{$route} has one H1");
            $this->assertStringContainsString('data-ad-row-menu="'.$template.'"', $html, $route);
            $this->assertStringContainsString('<template id="'.$template.'">', $html, $route);
            $this->assertStringContainsString(e($row), $html, "{$route} lists its row");
            $this->assertStringContainsString('class="ad-iconbtn is-more"', $html, $route);
            $this->assertStringNotContainsString('data-ad-legacy="on"', $html, "{$route} is a new page");
        }
    }

    /** The lists moved to the new table in wave 3: rows, one row-menu template (where rows have actions), one H1. */
    public function test_the_other_lists_show_their_rows_in_the_new_admin(): void
    {
        config(['admin.ui' => 'v2', 'admin.operational_tools_enabled' => true]);
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', true);
        self::seedOtherLists($admin);
        Storage::fake('public');
        Storage::disk('public')->put('covers/cover-one.jpg', 'x');
        Storage::disk('public')->put('readme-file.txt', 'x');

        $lists = [
            'admin.devices.index' => ['ad-device-menu', 'Pixel tablet'],
            'admin.password-resets.index' => ['ad-reset-menu', 'forgot@example.test'],
            'admin.activities.index' => ['ad-activity-menu', 'Commented'],
            'admin.notifications.index' => ['ad-notification-menu', 'Big news'],
            'admin.featured.index' => ['ad-featured-menu', 'Spotlight pick'],
            'admin.views.index' => ['ad-view-menu', 'Watched video'],
            'admin.searches.index' => [null, 'night drive'],
            'admin.interactions.index' => ['ad-interaction-menu', 'Watched video'],
            'admin.media.index' => ['ad-file-menu', 'readme-file.txt'],
            'admin.thumbnails.index' => ['ad-thumbnail-menu', 'Watched video'],
            'admin.exports.index' => ['ad-export-menu', 'members-export.xlsx'],
            'admin.subscriptions.index' => ['ad-subscription-menu', 'Gold monthly'],
            'admin.premiums.index' => ['ad-premium-menu', 'Gold monthly'],
            'admin.transactions.index' => ['ad-payment-menu', 'ch_test_123'],
            'admin.invoices.index' => ['ad-invoice-menu', 'paying_member'],
            'admin.failed-jobs.index' => ['ad-failed-menu', 'Run Avdb Import'],
            'admin.audit.index' => [null, 'settings.update'],
            'admin.broadcast.index' => [null, 'Big news'],
            'admin.spams.index' => [null, 'name="emails"'],
        ];

        foreach ($lists as $route => [$template, $row]) {
            $html = $this->actingAs($admin)->get(route($route))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, '<h1'), "{$route} has one H1");
            $this->assertStringContainsString(str_starts_with($row, 'name=') ? $row : e($row), $html, "{$route} lists its row");
            $this->assertStringNotContainsString('data-ad-legacy="on"', $html, "{$route} is a new page");

            if ($template !== null) {
                $this->assertStringContainsString('data-ad-row-menu="'.$template.'"', $html, $route);
                $this->assertStringContainsString('<template id="'.$template.'">', $html, $route);
            }
        }

        // The whole error of one failed job, its line breaks kept.
        $this->actingAs($admin)
            ->get(route('admin.failed-jobs.index', ['show' => 'b6f0c1aa-0000-4000-8000-000000000001']))
            ->assertOk()
            ->assertSee('RuntimeException: Boom&#10;#0 {main}', false);

        // Sessions are listed from the database store (the test database's own connection).
        config(['session.driver' => 'database', 'session.connection' => null]);
        DB::table('sessions')->insert(['id' => 'admb-visitor-session', 'user_id' => null, 'ip_address' => '198.51.100.9', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36', 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->actingAs($admin)
            ->get(route('admin.sessions.index'))
            ->assertOk()
            ->assertSee('data-ad-row-menu="ad-session-menu"', false)
            ->assertSee('198.51.100.9')
            ->assertSee('Chrome on Windows');
    }

    /** The two form pages send the same fields to the same addresses as the old ones. */
    public function test_the_broadcast_and_spam_forms_keep_their_fields(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();

        $broadcast = $this->actingAs($admin)->get(route('admin.broadcast.index'))->assertOk()->getContent();
        $this->assertStringContainsString('action="'.route('admin.broadcast.send', [], false).'"', $broadcast);
        foreach (['target', 'user_ids', 'title', 'message'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $broadcast);
        }

        $notifications = $this->actingAs($admin)->get(route('admin.notifications.index'))->assertOk()->getContent();
        $this->assertStringContainsString('action="'.route('admin.notifications.broadcast', [], false).'"', $notifications);

        $spam = $this->actingAs($admin)->get(route('admin.spams.index'))->assertOk()->getContent();
        $this->assertStringContainsString('action="'.route('admin.spams.update').'"', $spam);
        $this->assertStringContainsString('name="_method" value="PUT"', $spam);
    }

    /** One row in each table the wave-3 lists read (also used by AdminListQueryCountTest). */
    public static function seedOtherLists(User $admin, int $n = 1, string $tag = ''): void
    {
        for ($i = 0; $i < $n; $i++) {
            $member = User::factory()->create(['username' => $i === 0 && $tag === '' ? 'paying_member' : 'member_'.$tag.$i.'_'.uniqid()]);
            $video = Video::factory()->for($member)->create(['title' => 'Watched video']);
            $plan = Plan::query()->create(['name' => 'Gold monthly', 'price' => 999, 'duration' => 1]);
            $key = $tag.$i.'-'.$video->id;

            DB::table('personal_access_tokens')->insert(['tokenable_type' => User::class, 'tokenable_id' => $member->id, 'name' => 'Pixel tablet', 'token' => hash('sha256', 'token-'.$key), 'abilities' => '["*"]', 'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('password_reset_tokens')->insert(['email' => $i === 0 && $tag === '' ? 'forgot@example.test' : 'forgot-'.$key.'@example.test', 'token' => 'hash', 'created_at' => now()]);
            DB::table('activities')->insert(['user_id' => $member->id, 'subject_type' => Comment::class, 'subject_id' => $video->id, 'perform_at' => now()]);
            DB::table('notifications')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'App\\Notifications\\AdminBroadcast', 'notifiable_type' => User::class, 'notifiable_id' => $member->id, 'data' => json_encode(['title' => 'Big news', 'message' => 'Hello members']), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('featured_content')->insert(['type' => 'video', 'video_id' => $video->id, 'title' => 'Spotlight pick', 'position' => 'homepage_hero', 'sort_order' => $i, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('views')->insert(['video_id' => $video->id, 'user_id' => $member->id, 'ip' => '203.0.113.'.($i % 250), 'view_at' => now()]);
            DB::table('searches')->insert(['query' => 'night drive '.$key, 'ip' => '203.0.113.1', 'results' => 0, 'perform_at' => now()]);
            DB::table('interactions')->insert(['user_id' => $member->id, 'likeable_type' => Video::class, 'likeable_id' => $video->id, 'status' => 1, 'perform_at' => now()]);
            DB::table('thumbnails')->insert(['video_id' => $video->id, 'file' => 'thumb-'.$key.'.jpg', 'is_active' => true, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('exports')->insert(['file' => 'members-export.xlsx', 'size' => 1234, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('premiums')->insert(['payment_provider' => 'stripe', 'stripe_status' => 'active', 'user_id' => $member->id, 'plan_id' => $plan->id, 'stripe_id' => 'sub_'.$key, 'card_last4' => '4242', 'card_expired_at' => now()->addYear()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('transactions')->insert(['payment_provider' => 'stripe', 'stripe_id' => $i === 0 && $tag === '' ? 'ch_test_123' : 'ch_'.$key, 'amount' => 999, 'tax' => 100, 'fee' => 30, 'date' => now(), 'user_id' => $member->id, 'name' => 'Paying Member', 'address' => 'Street 1', 'city' => 'Town', 'postal_code' => '1000', 'country' => 'de']);
            DB::table('failed_jobs')->insert(['uuid' => $i === 0 && $tag === '' ? 'b6f0c1aa-0000-4000-8000-000000000001' : (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['uuid' => 'x', 'displayName' => RunAvdbImport::class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call']), 'exception' => "RuntimeException: Boom\n#0 {main}", 'failed_at' => now()]);
            DB::table('admin_audit_logs')->insert(['user_id' => $admin->id, 'action' => 'settings.update', 'details' => 'Changed the site name', 'ip_address' => '127.0.0.1', 'created_at' => now()]);
        }
    }

    public function test_the_new_backups_list_shows_the_backup_files(): void
    {
        config(['admin.ui' => 'v2', 'admin.operational_tools_enabled' => true]);
        $admin = User::factory()->admin()->create();
        $dir = storage_path('app/backups');
        $file = $dir.'/backup_wp9_smoke_test.sql';
        $created = ! is_dir($dir) && mkdir($dir, 0755, true);
        file_put_contents($file, '-- test');

        try {
            $this->actingAs($admin)
                ->get(route('admin.backups.index'))
                ->assertOk()
                ->assertSee('backup_wp9_smoke_test.sql')
                ->assertSee('data-ad-row-menu="ad-backup-menu"', false)
                ->assertSee('data-id="backup_wp9_smoke_test.sql"', false)
                ->assertSee('action="'.route('admin.backups.create').'"', false);
        } finally {
            @unlink($file);

            if ($created) {
                @rmdir($dir);
            }
        }
    }

    public function test_the_new_reports_list_opens_on_the_waiting_reports(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        $video = Video::factory()->for($admin)->create(['title' => 'Waiting one']);
        $other = Video::factory()->for($admin)->create(['title' => 'Dismissed one']);
        Report::factory()->for($video, 'reportable')->create(['status' => ReportStatus::PENDING->value]);
        Report::factory()->for($other, 'reportable')->create(['status' => ReportStatus::CANCELLED->value]);

        $this->actingAs($admin)->get(route('admin.reports.index'))->assertOk()->assertSee('Waiting one')->assertDontSee('Dismissed one');
        $this->actingAs($admin)->get(route('admin.reports.index', ['status' => 'all']))->assertOk()->assertSee('Waiting one')->assertSee('Dismissed one');
        $this->actingAs($admin)->get(route('admin.reports.index', ['status' => ReportStatus::CANCELLED->value]))->assertOk()->assertDontSee('Waiting one')->assertSee('Dismissed one');
    }

    public function test_the_old_reports_page_still_says_a_reported_comments_video_was_deleted(): void
    {
        config(['admin.ui' => 'v1']);
        $admin = User::factory()->admin()->create();
        $video = Video::factory()->for($admin)->create(['title' => 'A removed video']);
        $comment = Comment::factory()->for($video)->for($admin)->create();
        Report::factory()->for($comment, 'reportable')->create();
        $video->delete();

        $this->actingAs($admin)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Video deleted')
            ->assertDontSee('A removed video');
    }

    public function test_the_video_record_page_sends_its_switches_only_when_they_are_on(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        $video = Video::factory()->for($admin)->create(['show_ad' => false, 'allow_comments' => true]);

        // VideoController::update() reads a switch as on when it is sent: no hidden "0" may go with it.
        $this->actingAs($admin)
            ->get(route('admin.videos.edit', $video))
            ->assertOk()
            ->assertSee('id="ad-video-form"', false)
            ->assertSee('data-ad-form', false)
            ->assertSee('name="allow_comments" value="1" checked', false)
            ->assertDontSee('type="hidden" name="show_ad"', false)
            ->assertDontSee('type="hidden" name="allow_comments"', false);

        $this->actingAs($admin)->get(route('admin.videos.edit', $video).'?theme_mode=dark')->assertOk();
        config(['admin.ui' => 'v1']);
        $this->actingAs($admin)->get(route('admin.videos.edit', $video))->assertOk()->assertSee('Back to Videos');
    }

    public function test_the_translation_editor_shows_100_strings_and_saves_only_its_page(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        DB::table('languages')->insert(['code' => 'zz', 'label' => 'Test language', 'emoji' => '', 'direction' => 'ltr', 'is_active' => false, 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        $english = array_slice(json_decode((string) file_get_contents(lang_path('en.json')), true), 0, 3, true);
        [$first, $second, $third] = array_keys($english);
        $file = lang_path('zz.json');
        file_put_contents($file, json_encode([$first => 'One', $second => 'Two', $third => 'Three']));

        try {
            $this->actingAs($admin)
                ->get(route('admin.languages.edit', ['locale' => 'zz']))
                ->assertOk()
                ->assertSee('name="partial" value="1"', false)
                ->assertSee('Showing 1-100 of', false);

            // One page of strings: those keys change (an emptied one is removed), every other key stays.
            $this->actingAs($admin)
                ->put(route('admin.languages.update', ['locale' => 'zz']), ['partial' => 1, 'translations' => [$first => 'Uno', $second => '']])
                ->assertRedirect();
            $this->assertSame([$first => 'Uno', $third => 'Three'], json_decode((string) file_get_contents($file), true));

            // Without "partial" the form is the whole file, as the old editor sends it.
            $this->actingAs($admin)
                ->put(route('admin.languages.update', ['locale' => 'zz']), ['translations' => [$second => 'Dos']])
                ->assertRedirect(route('admin.languages.edit', 'zz'));
            $this->assertSame([$second => 'Dos'], json_decode((string) file_get_contents($file), true));
        } finally {
            @unlink($file);
            @rmdir(lang_path('zz'));
        }
    }

    public function test_thumbnail_page_shows_visible_pagination_when_there_are_many_thumbnails(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();

        Video::factory()
            ->count(25)
            ->for($owner)
            ->create()
            ->each(fn (Video $video) => Thumbnail::factory()->generated()->for($video)->create());

        $this->actingAs($admin)
            ->get(route('admin.thumbnails.index'))
            ->assertOk()
            ->assertSee('Page 1')
            ->assertSee('25')
            ->assertSee('results');
    }

    public function test_admin_videos_can_choose_larger_page_size(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();

        Video::factory()
            ->count(60)
            ->for($owner)
            ->create();

        $this->actingAs($admin)
            ->get(route('admin.videos.index', ['per_page' => 50]))
            ->assertOk()
            ->assertSee('Showing')
            ->assertSee('1')
            ->assertSee('50')
            ->assertSee('60')
            ->assertSee('value="1000"', false)
            ->assertSee('name="per_page"', false)
            ->assertSee('data-native-select', false)
            ->assertSee('Results per page', false);
    }

    public function test_admin_users_can_choose_larger_page_size(): void
    {
        $admin = User::factory()->admin()->create();

        User::factory()
            ->count(60)
            ->create();

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['per_page' => 50]))
            ->assertOk()
            ->assertSee('Showing')
            ->assertSee('1')
            ->assertSee('50')
            ->assertSee('61')
            ->assertSee('value="1000"', false)
            ->assertSee('name="per_page"', false)
            ->assertSee('data-native-select', false)
            ->assertSee('Results per page', false);
    }

    public function test_admin_verification_page_ignores_soft_deleted_users(): void
    {
        $admin = User::factory()->admin()->create();
        $visible = User::factory()->create(['username' => 'visible_verification_user']);
        $deleted = User::factory()->create(['username' => 'deleted_verification_user']);

        $deleted->delete();

        $this->actingAs($admin)
            ->get(route('admin.verification.index'))
            ->assertOk()
            ->assertSee($visible->username)
            ->assertDontSee($deleted->username)
            ->assertDontSee($deleted->email);
    }

    public function test_admin_verify_all_does_not_touch_soft_deleted_users(): void
    {
        $admin = User::factory()->admin()->create();
        $deleted = User::factory()->create(['email_verified_at' => null]);

        $deleted->delete();

        $this->actingAs($admin)
            ->post(route('admin.verification.verify-all'))
            ->assertRedirect(route('admin.verification.index'));

        $this->assertNull(User::withTrashed()->findOrFail($deleted->id)->email_verified_at);
    }

    public function test_user_force_delete_removes_session_and_password_reset_artifacts(): void
    {
        $user = User::factory()->create();

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => 'stale-token',
            'created_at' => now(),
        ]);

        DB::table('sessions')->insert([
            'id' => 'stale-user-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $user->forceDelete();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseMissing('sessions', ['id' => 'stale-user-session']);
    }

    /** The old admin's Make / Remove administrator button (users.is_admin is not mass-assignable). */
    public function test_the_old_admin_makes_and_removes_an_administrator(): void
    {
        config(['admin.ui' => 'v1']);
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $edit = route('admin.users.edit', $member);

        $this->actingAs($admin)->from($edit)
            ->post(route('admin.users.toggle-admin', $member))
            ->assertRedirect($edit)
            ->assertSessionHas('success', 'User promoted to admin successfully.');
        $this->assertTrue($member->fresh()->is_admin);

        $this->actingAs($admin)->from($edit)
            ->post(route('admin.users.toggle-admin', $member))
            ->assertRedirect($edit)
            ->assertSessionHas('success', 'User removed from admin successfully.');
        $this->assertFalse($member->fresh()->is_admin);

        // An admin never takes their own rights away (as the bulk action).
        $this->actingAs($admin)->from(route('admin.users.edit', $admin))
            ->post(route('admin.users.toggle-admin', $admin))
            ->assertRedirect(route('admin.users.edit', $admin))
            ->assertSessionHasErrors('user');
        $this->assertTrue($admin->fresh()->is_admin);
    }

    #[DataProvider('adminPaginatedPages')]
    public function test_admin_paginated_pages_have_real_per_page_selector(string $routeName): void
    {
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'developer_mode', true);

        $this->actingAs($admin)
            ->get(route($routeName, ['per_page' => 1000]))
            ->assertOk()
            ->assertSee('name="per_page"', false)
            ->assertSee('value="1000"', false)
            ->assertSee('data-native-select', false);
    }

    public function test_avdb_clean_finished_action_url_redirects_to_import_page_with_guidance(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/avdb-import/runs/clean-finished')
            ->assertRedirect(route('admin.avdb-import.index'))
            ->assertSessionHasErrors();
    }

    public function test_avdb_clean_preserves_non_avdb_tags(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $avdbVideo = Video::factory()->for($owner)->create(['avdb_id' => 12345]);
        $nativeVideo = Video::factory()->for($owner)->create(['avdb_id' => null]);
        $sharedTag = Tag::query()->create(['name' => 'Shared', 'slug' => 'shared']);
        $nativeTag = Tag::query()->create(['name' => 'Native', 'slug' => 'native']);
        $avdbOnlyTag = Tag::query()->create(['name' => 'Avdb Only', 'slug' => 'avdb-only']);

        DB::table('video_tags')->insert([
            ['video_id' => $avdbVideo->id, 'tag_id' => $sharedTag->id, 'created_at' => now(), 'updated_at' => now()],
            ['video_id' => $nativeVideo->id, 'tag_id' => $sharedTag->id, 'created_at' => now(), 'updated_at' => now()],
            ['video_id' => $nativeVideo->id, 'tag_id' => $nativeTag->id, 'created_at' => now(), 'updated_at' => now()],
            ['video_id' => $avdbVideo->id, 'tag_id' => $avdbOnlyTag->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('tag_translations')->insert([
            ['tag_id' => $sharedTag->id, 'locale' => 'fr', 'name' => 'Partage', 'created_at' => now(), 'updated_at' => now()],
            ['tag_id' => $avdbOnlyTag->id, 'locale' => 'fr', 'name' => 'Avdb Seulement', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.avdb-import.clean'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'deleted' => 1,
            ]);

        $this->assertDatabaseMissing('videos', ['id' => $avdbVideo->id]);
        $this->assertDatabaseHas('videos', ['id' => $nativeVideo->id]);
        $this->assertDatabaseHas('tags', ['id' => $sharedTag->id]);
        $this->assertDatabaseHas('tags', ['id' => $nativeTag->id]);
        $this->assertDatabaseMissing('tags', ['id' => $avdbOnlyTag->id]);
        $this->assertDatabaseHas('tag_translations', ['tag_id' => $sharedTag->id]);
        $this->assertDatabaseMissing('tag_translations', ['tag_id' => $avdbOnlyTag->id]);
    }

    public function test_avdb_clean_cancels_active_imports_and_removes_avdb_queue_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $avdbVideo = Video::factory()->for($owner)->create(['avdb_id' => 98765]);
        $activeRun = AvdbImportRun::query()->create([
            'status' => 'running',
            'settings' => [],
            'message' => 'Import running.',
            'started_at' => now(),
        ]);
        $now = now()->timestamp;

        DB::table('jobs')->insert([
            [
                'queue' => 'default',
                'payload' => json_encode(['displayName' => RunAvdbImport::class, 'data' => ['command' => '']]),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now,
                'created_at' => $now,
            ],
            [
                'queue' => 'default',
                'payload' => json_encode(['displayName' => SyncAvdbDurationBatch::class, 'data' => ['command' => '']]),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now,
                'created_at' => $now,
            ],
            [
                'queue' => 'uploads',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\UploadToUpload18', 'data' => ['command' => '']]),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now,
                'created_at' => $now,
            ],
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.avdb-import.clean'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'deleted' => 1,
            ])
            ->assertJsonFragment([
                'message' => '1 AVDB videos permanently deleted. 0 orphan AVDB tag(s) were removed. Stop requested for 1 active AVDB import run(s). Removed 2 queued AVDB import/duration job(s).',
            ]);

        $this->assertDatabaseMissing('videos', ['id' => $avdbVideo->id]);
        $this->assertDatabaseHas('avdb_import_runs', [
            'id' => $activeRun->id,
            'cancel_requested' => true,
        ]);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertDatabaseHas('jobs', ['queue' => 'uploads']);
    }

    /**
     * Main read-only admin pages that should render without route parameters.
     *
     * Vendor dashboards and destructive/action routes are intentionally excluded.
     */
    public static function adminPages(): array
    {
        return [
            'dashboard' => ['admin.index'],
            'activities' => ['admin.activities.index'],
            'announcements' => ['admin.announcements.index'],
            'api tokens' => ['admin.api-tokens.index'],
            'artisan' => ['admin.artisan.index'],
            'audit' => ['admin.audit.index'],
            'avdb import' => ['admin.avdb-import.index'],
            'backups' => ['admin.backups.index'],
            'broadcast' => ['admin.broadcast.index'],
            'categories' => ['admin.categories.index'],
            'category create' => ['admin.categories.create'],
            'comments' => ['admin.comments.index'],
            'contacts' => ['admin.contacts.index'],
            'cron' => ['admin.cron.index'],
            'database' => ['admin.database.index'],
            'devices' => ['admin.devices.index'],
            'email test' => ['admin.email-test.index'],
            'env editor' => ['admin.env-editor.index'],
            'exports' => ['admin.exports.index'],
            'failed jobs' => ['admin.failed-jobs.index'],
            'featured' => ['admin.featured.index'],
            'interactions' => ['admin.interactions.index'],
            'invoices' => ['admin.invoices.index'],
            'ip bans' => ['admin.ip-bans.index'],
            'languages' => ['admin.languages.index'],
            'language create' => ['admin.languages.create'],
            'logs viewer' => ['admin.logs.index'],
            'media' => ['admin.media.index'],
            'notifications' => ['admin.notifications.index'],
            'pages' => ['admin.pages.index'],
            'page create' => ['admin.pages.create'],
            'password resets' => ['admin.password-resets.index'],
            'php info' => ['admin.phpinfo.index'],
            'plans' => ['admin.plans.index'],
            'plan create' => ['admin.plans.create'],
            'playlists' => ['admin.playlists.index'],
            'premiums' => ['admin.premiums.index'],
            'preflight' => ['admin.preflight.index'],
            'queue' => ['admin.queue.index'],
            'redirects' => ['admin.redirects.index'],
            'reports' => ['admin.reports.index'],
            'searches' => ['admin.searches.index'],
            'sessions' => ['admin.sessions.index'],
            'settings' => ['admin.settings.index'],
            'spam' => ['admin.spams.index'],
            'storage' => ['admin.storage.index'],
            'subscriptions' => ['admin.subscriptions.index'],
            'system' => ['admin.system.index'],
            'tags' => ['admin.tags.index'],
            'thumbnails' => ['admin.thumbnails.index'],
            'transactions' => ['admin.transactions.index'],
            'users' => ['admin.users.index'],
            'verification' => ['admin.verification.index'],
            'videos' => ['admin.videos.index'],
            'views' => ['admin.views.index'],
            'xvid import' => ['admin.xvid-import.index'],
        ];
    }

    public static function adminPaginatedPages(): array
    {
        return [
            'activities' => ['admin.activities.index'],
            'announcements' => ['admin.announcements.index'],
            'api tokens' => ['admin.api-tokens.index'],
            'audit' => ['admin.audit.index'],
            'backups' => ['admin.backups.index'],
            'categories' => ['admin.categories.index'],
            'comments' => ['admin.comments.index'],
            'contacts' => ['admin.contacts.index'],
            'devices' => ['admin.devices.index'],
            'exports' => ['admin.exports.index'],
            'failed jobs' => ['admin.failed-jobs.index'],
            'featured' => ['admin.featured.index'],
            'interactions' => ['admin.interactions.index'],
            'invoices' => ['admin.invoices.index'],
            'ip bans' => ['admin.ip-bans.index'],
            'logs viewer' => ['admin.logs.index'],
            'media' => ['admin.media.index'],
            'notifications' => ['admin.notifications.index'],
            'pages' => ['admin.pages.index'],
            'password resets' => ['admin.password-resets.index'],
            'plans' => ['admin.plans.index'],
            'playlists' => ['admin.playlists.index'],
            'premiums' => ['admin.premiums.index'],
            'queue' => ['admin.queue.index'],
            'redirects' => ['admin.redirects.index'],
            'reports' => ['admin.reports.index'],
            'searches' => ['admin.searches.index'],
            'subscriptions' => ['admin.subscriptions.index'],
            'tags' => ['admin.tags.index'],
            'thumbnails' => ['admin.thumbnails.index'],
            'transactions' => ['admin.transactions.index'],
            'users' => ['admin.users.index'],
            'verification' => ['admin.verification.index'],
            'videos' => ['admin.videos.index'],
            'views' => ['admin.views.index'],
        ];
    }
}
