<?php

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Enums\VideoStatus;
use App\Events\UserBanned;
use App\Events\Video\VideoBanned;
use App\Http\Controllers\Admin\EnvEditorController;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Report;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use App\Support\AdminPreferences;
use App\Support\AuditActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Lab404\Impersonate\Events\TakeImpersonation;
use Tests\TestCase;

/**
 * Tools > Logs > Admin actions records what administrators change (R2A-020; 1.2.2 recorded only updates, the queue and
 * scheduler tools, payment keys and the admin IP lock, while its empty state said "Changes administrators make are
 * written here."): role changes, blocks, view-only on/off, settings saves (which settings, never a value, a password
 * or key neither), video bans and unbans, the bulk actions of every list (a row menu posts there with one item), and
 * the tools' changes. One row per action, naming what changed; an action that changed nothing writes nothing. Each
 * action has its plain-words label (AuditActionLabelsTest checks every key the code writes).
 */
class AdminActionsLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.operational_tools_enabled' => true]);
        $this->admin = User::factory()->admin()->create(['username' => 'boss']);
    }

    /** @return list<object> the rows written so far, oldest first */
    private function rows(?string $action = null): array
    {
        return DB::table('admin_audit_logs')->when($action, fn ($query) => $query->where('action', $action))->orderBy('id')->get()->all();
    }

    private function onlyRow(string $action): object
    {
        $rows = $this->rows($action);
        $this->assertCount(1, $rows, "one {$action} row");
        $this->assertSame((int) $this->admin->id, (int) $rows[0]->user_id, 'the administrator who did it');

        return $rows[0];
    }

    public function test_making_and_removing_an_administrator_is_written_in_both_admins(): void
    {
        $member = User::factory()->create(['username' => 'alice']);

        // the old admin's button
        $this->actingAs($this->admin)->post(route('admin.users.toggle-admin', $member))->assertRedirect();
        $this->assertSame('alice (#'.$member->id.')', $this->onlyRow('member.admin.granted')->details);
        $this->actingAs($this->admin)->post(route('admin.users.toggle-admin', $member))->assertRedirect();
        $this->assertSame('alice (#'.$member->id.')', $this->onlyRow('member.admin.removed')->details);

        // the new admin's member page and list (the bulk endpoint): one member, several, and one that changes nothing
        DB::table('admin_audit_logs')->delete();
        $bob = User::factory()->create(['username' => 'bob']);
        $this->actingAs($this->admin)->post(route('admin.bulk.users'), ['ids' => [$member->id], 'action' => 'make_admin'])->assertRedirect();
        $this->assertSame('alice (#'.$member->id.')', $this->onlyRow('member.admin.granted')->details);
        $this->actingAs($this->admin)->post(route('admin.bulk.users'), ['ids' => [$member->id, $bob->id], 'action' => 'make_admin'])->assertRedirect();
        $this->assertSame('bob (#'.$bob->id.')', $this->rows('member.admin.granted')[1]->details, 'only bob changed');
        $this->actingAs($this->admin)->post(route('admin.bulk.users'), ['ids' => [$member->id, $bob->id], 'action' => 'remove_admin'])->assertRedirect();
        $this->assertSame('alice (#'.$member->id.'), bob (#'.$bob->id.')', $this->onlyRow('members.admin.removed')->details);
        $count = count($this->rows());
        $this->actingAs($this->admin)->post(route('admin.bulk.users'), ['ids' => [$bob->id], 'action' => 'remove_admin'])->assertRedirect();
        $this->assertCount($count, $this->rows(), 'nothing changed, nothing written');
    }

    public function test_blocks_view_only_email_and_member_edits_are_written_without_any_value(): void
    {
        $member = User::factory()->create(['username' => 'carol', 'email_verified_at' => null]);

        $this->actingAs($this->admin)->post(route('admin.users.ban', $member), ['ban_videos' => '1', 'ban_comments' => '1'])->assertRedirect();
        $this->assertSame('carol (#'.$member->id.'), with their videos and comments', $this->onlyRow('member.blocked')->details);
        $this->actingAs($this->admin)->post(route('admin.users.unban', $member))->assertRedirect();
        $this->assertSame('carol (#'.$member->id.')', $this->onlyRow('member.unblocked')->details);

        $this->actingAs($this->admin)->post(route('admin.users.view-only', $member), ['view_only' => '1'])->assertRedirect();
        $this->onlyRow('member.view_only.on');
        $this->actingAs($this->admin)->post(route('admin.users.view-only', $member), ['view_only' => '0'])->assertRedirect();
        $this->onlyRow('member.view_only.off');

        $this->actingAs($this->admin)->post(route('admin.users.confirm', $member))->assertRedirect();
        $this->onlyRow('member.email_confirmed');
        $this->actingAs($this->admin)->post(route('admin.users.unverify', $member))->assertRedirect();
        $this->onlyRow('member.email_unconfirmed');

        $this->actingAs($this->admin)->put(route('admin.users.update', $member), [
            'username' => 'carol2', 'email' => 'carol2@example.test', 'password' => 'A-new-secret-9z', 'description' => $member->description,
        ])->assertRedirect();
        $row = $this->onlyRow('member.updated');
        $this->assertSame('carol2 (#'.$member->id.'): username, email, password', $row->details);
        $this->assertStringNotContainsString('A-new-secret-9z', json_encode($this->rows()), 'never a password');

        // bulk: several members at once, blocked and unblocked
        $dave = User::factory()->create(['username' => 'dave']);
        $this->actingAs($this->admin)->post(route('admin.bulk.users'), ['ids' => [$member->id, $dave->id, $this->admin->id], 'action' => 'ban'])->assertRedirect();
        $this->assertSame('carol2 (#'.$member->id.'), dave (#'.$dave->id.')', $this->onlyRow('members.blocked')->details, 'never the administrator themself');
        $this->actingAs($this->admin)->post(route('admin.bulk.users'), ['ids' => [$member->id], 'action' => 'unban'])->assertRedirect();
        $this->assertCount(2, $this->rows('member.unblocked'));

        // deleting names the member as they were
        $this->actingAs($this->admin)->delete(route('admin.users.delete', $dave))->assertRedirect();
        $this->assertSame('dave (#'.$dave->id.')', $this->onlyRow('member.deleted')->details);
    }

    public function test_a_settings_save_names_the_settings_that_changed_never_a_value(): void
    {
        Setting::set('site_name', 'Before', 'general');

        $this->actingAs($this->admin)->putJson('/admin/settings/save', [
            'groups' => ['general' => ['site_name' => 'After Name'], 'email' => ['smtp_password' => 'Smtp-Secret-123', 'smtp_host' => 'mail.example.test']],
            'fields' => ['general' => ['site_name'], 'email' => ['smtp_password', 'smtp_host']],
        ])->assertOk();

        $row = $this->onlyRow('settings.saved');
        $keys = explode(', ', (string) $row->details);
        sort($keys);
        $this->assertSame(['site_name', 'smtp_host', 'smtp_password'], $keys);
        $this->assertStringNotContainsString('Smtp-Secret-123', json_encode($this->rows()));
        $this->assertStringNotContainsString('After Name', json_encode($this->rows()));

        // the same values again: nothing changed, nothing written (an empty password field keeps the password)
        $this->actingAs($this->admin)->putJson('/admin/settings/save', [
            'groups' => ['general' => ['site_name' => 'After Name'], 'email' => ['smtp_password' => '']],
            'fields' => ['general' => ['site_name'], 'email' => ['smtp_password']],
        ])->assertOk();
        $this->assertCount(1, $this->rows('settings.saved'));

        // the old settings page saves one group the same way (it posts the whole group, as stored, with one change)
        $form = array_map('strval', array_filter(Setting::getGroup('sociallinks'), fn ($value): bool => $value !== null));
        $form['social_facebook'] = 'https://facebook.com/example';
        $this->actingAs($this->admin)->put(route('admin.settings.update.group', 'sociallinks'), $form)->assertRedirect();
        $this->assertSame('social_facebook', $this->rows('settings.saved')[1]->details);
    }

    public function test_the_site_default_language_set_on_the_languages_page_is_a_settings_save(): void
    {
        // the installed languages (the migrations add them): English the default, French active
        \App\Models\Language::query()->where('is_default', true)->update(['is_default' => false]);
        \App\Models\Language::query()->updateOrCreate(['code' => 'en'], ['label' => 'English', 'emoji' => '', 'direction' => 'ltr', 'is_active' => true, 'is_default' => true, 'sort_order' => 1]);
        $french = \App\Models\Language::query()->updateOrCreate(['code' => 'fr'], ['label' => 'Français', 'emoji' => '', 'direction' => 'ltr', 'is_active' => true, 'is_default' => false, 'sort_order' => 2]);
        Setting::set('default_locale', 'en', 'localization');

        $this->actingAs($this->admin)->post(route('admin.languages.set-default', $french))->assertRedirect();
        $this->assertSame('default_locale', $this->onlyRow('settings.saved')->details);

        // the same language again: nothing changed, nothing written
        $this->actingAs($this->admin)->post(route('admin.languages.set-default', $french->fresh()))->assertRedirect();
        $this->assertCount(1, $this->rows('settings.saved'));
    }

    public function test_video_bans_unbans_visibility_and_deletes_are_written(): void
    {
        $owner = User::factory()->create();
        $video = Video::factory()->for($owner)->create(['title' => 'Sunset <drive>', 'status' => VideoStatus::PUBLIC->value]);
        $other = Video::factory()->for($owner)->create(['title' => 'Night', 'status' => VideoStatus::PRIVATE->value]);

        $this->actingAs($this->admin)->post(route('admin.videos.ban', $video))->assertRedirect();
        $this->assertSame('Sunset <drive> (#'.$video->id.')', $this->onlyRow('video.banned')->details);
        $this->actingAs($this->admin)->post(route('admin.videos.unban', $video))->assertRedirect();
        $this->onlyRow('video.unbanned');

        // the new admin's list and row menus (the bulk endpoint)
        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['ids' => [$video->id, $other->id], 'action' => 'ban'])->assertRedirect();
        $this->assertSame('Sunset <drive> (#'.$video->id.'), Night (#'.$other->id.')', $this->onlyRow('videos.banned')->details);
        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['ids' => [$other->id], 'action' => 'unban'])->assertRedirect();
        $this->assertSame('Night (#'.$other->id.')', $this->rows('video.unbanned')[1]->details);
        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['ids' => [$other->id], 'action' => 'make_public'])->assertRedirect();
        $this->onlyRow('video.made_public');
        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['ids' => [$other->id], 'action' => 'make_public'])->assertRedirect();
        $this->assertCount(1, $this->rows('video.made_public'), 'already public: nothing written');

        $this->actingAs($this->admin)->delete(route('admin.videos.delete', $other))->assertRedirect();
        $this->assertSame('Night (#'.$other->id.')', $this->onlyRow('video.deleted')->details);
    }

    public function test_long_lists_name_the_first_ones_and_how_many_more(): void
    {
        $members = User::factory()->count(28)->create();

        $this->actingAs($this->admin)->post(route('admin.bulk.users'), ['ids' => $members->pluck('id')->all(), 'action' => 'ban'])->assertRedirect();

        $this->assertStringEndsWith(' and 3 more', (string) $this->onlyRow('members.blocked')->details);
    }

    public function test_the_tools_and_security_pages_write_what_changed(): void
    {
        AdminPreferences::set($this->admin, 'developer_mode', true);

        // an administrator signed in as a member (the package switches the session, so the row names the admin by id)
        $member = User::factory()->create(['username' => 'erin']);
        Event::dispatch(new TakeImpersonation($this->admin, $member));
        $this->assertSame('erin (#'.$member->id.')', $this->onlyRow('member.impersonated')->details);

        $this->actingAs($this->admin)->post(route('admin.artisan.run'), ['command' => 'view:clear'])->assertRedirect();
        $this->assertSame('view:clear', $this->onlyRow('tools.command.run')->details);

        $this->assertSame(['APP_DEBUG', 'MAIL_HOST', 'NEW_ONE'], EnvEditorController::changedNames(
            "APP_NAME=Site\nAPP_DEBUG=false\nMAIL_HOST=a.example\nGONE=x\n",
            "APP_NAME=Site\nAPP_DEBUG=true\nMAIL_HOST=b.example\nNEW_ONE=1\nGONE=x\n"
        ), 'the names that changed, never a value');

        $this->actingAs($this->admin)->post(route('admin.ip-bans.store'), ['ip_address' => '203.0.113.9'])->assertRedirect();
        $this->assertSame('203.0.113.9', $this->onlyRow('security.address.blocked')->details);
    }

    /**
     * One row per action that changed something, never one for an action that changed nothing (judge R2CD-RV-03: the
     * single-row Block, Unblock, View-only, Ban and the reports' "Block it" wrote a row every time, as did the "clear
     * all" buttons of empty lists). A member or a video blocked already keeps the date of the block and gets no second
     * notice.
     */
    public function test_an_action_that_changes_nothing_writes_nothing(): void
    {
        Event::fake([UserBanned::class, VideoBanned::class]);
        $written = [];
        $answers = [];
        // how many rows each step wrote, and its answer (every step runs first, so a failure shows all of them)
        $step = function (string $label, callable $do) use (&$written, &$answers): void {
            $before = DB::table('admin_audit_logs')->count();
            $answers[$label] = $do();
            $written[$label] = DB::table('admin_audit_logs')->count() - $before;
        };
        $as = fn () => $this->actingAs($this->admin);

        // empty lists cleared and jobs that are not there (the empty log stays empty)
        foreach ([
            'clear the empty Admin actions log' => ['post', route('admin.audit.clear')],
            'clear the empty activity log' => ['delete', route('admin.activities.clear')],
            'delete every like (none)' => ['delete', route('admin.interactions.clear')],
            'delete every notification (none)' => ['delete', route('admin.notifications.clear')],
            'sign out every other session (none)' => ['post', route('admin.sessions.clear-all')],
            'revoke every API key (none)' => ['post', route('admin.api-tokens.revoke-all')],
            'retry every failed job (none)' => ['post', route('admin.failed-jobs.retry-all')],
            'retry a failed job that is gone' => ['post', route('admin.failed-jobs.retry', '3f2a9c1e-0000-4000-8000-00000000000a')],
            'delete a failed job that is gone' => ['delete', route('admin.failed-jobs.delete', '3f2a9c1e-0000-4000-8000-00000000000a')],
            'delete every failed job (none)' => ['delete', route('admin.failed-jobs.clear')],
            'empty an empty table' => ['delete', route('admin.database.truncate', 'searches')],
        ] as $label => [$method, $address]) {
            $step($label, fn () => $as()->{$method}($address));
        }

        $owner = User::factory()->create(['username' => 'gina']);
        $category = Category::query()->firstOrCreate(['slug' => 'nothing-changed'], ['title' => 'Nothing changed']);
        $video = Video::factory()->for($owner)->create(['category_id' => $category->id, 'title' => 'Calm', 'status' => VideoStatus::PUBLIC->value]);

        $step('unblock a member who is not blocked', fn () => $as()->post(route('admin.users.unban', $owner)));
        $step('block with their videos', fn () => $as()->post(route('admin.users.ban', $owner), ['ban_videos' => '1']));
        $blockedAt = (string) $owner->fresh()->banned_at;
        $this->travel(5)->minutes();
        // UserPolicy::ban refuses a member who is blocked already (a reloaded form)
        $step('block again (refused)', fn () => $as()->post(route('admin.users.ban', $owner), ['ban_videos' => '1']));
        $refusedAt = (string) $owner->fresh()->banned_at;
        $step('unblock', fn () => $as()->post(route('admin.users.unban', $owner)));
        $step('unblock again', fn () => $as()->post(route('admin.users.unban', $owner)));

        foreach (['view-only on' => '1', 'view-only on again' => '1', 'view-only off' => '0', 'view-only off again' => '0'] as $label => $value) {
            $step($label, fn () => $as()->post(route('admin.users.view-only', $owner), ['view_only' => $value]));
        }

        // banned with their member's videos above: Ban changes nothing, and says so
        $bannedAt = (string) $video->fresh()->banned_at;
        $step('ban a video banned already', fn () => $as()->post(route('admin.videos.ban', $video)));
        $said = session('success');
        $other = Video::factory()->for($owner)->create(['category_id' => $category->id, 'title' => 'Storm', 'status' => VideoStatus::PUBLIC->value]);
        $step('ban a video', fn () => $as()->post(route('admin.videos.ban', $other)));
        $step('ban it again', fn () => $as()->post(route('admin.videos.ban', $other)));

        // "Block it" on a reported comment; again; then a new report about the comment, hidden already
        $comment = Comment::query()->create(['video_id' => $other->id, 'user_id' => User::factory()->create()->id, 'content' => 'A reported comment', 'ip' => '127.0.0.1']);
        $report = fn (): Report => Report::query()->create([
            'user_id' => $owner->id, 'reportable_type' => Comment::class, 'reportable_id' => $comment->id, 'reason' => 1, 'status' => ReportStatus::PENDING,
        ]);
        $first = $report();
        $block = fn () => $as()->post(route('admin.reports.block', ['type' => 'comment', 'reportable' => $comment->id]));
        $step('block it: the reported comment', $block);
        $step('block it again', $block);
        $second = $report();
        $step('block it: a new report about the hidden comment', $block);

        $this->assertSame([
            'clear the empty Admin actions log' => 0, 'clear the empty activity log' => 0, 'delete every like (none)' => 0,
            'delete every notification (none)' => 0, 'sign out every other session (none)' => 0, 'revoke every API key (none)' => 0,
            'retry every failed job (none)' => 0, 'retry a failed job that is gone' => 0, 'delete a failed job that is gone' => 0,
            'delete every failed job (none)' => 0, 'empty an empty table' => 0,
            'unblock a member who is not blocked' => 0, 'block with their videos' => 1, 'block again (refused)' => 0,
            'unblock' => 1, 'unblock again' => 0,
            'view-only on' => 1, 'view-only on again' => 0, 'view-only off' => 1, 'view-only off again' => 0,
            'ban a video banned already' => 0, 'ban a video' => 1, 'ban it again' => 0,
            'block it: the reported comment' => 1, 'block it again' => 0, 'block it: a new report about the hidden comment' => 1,
        ], $written);

        // every answer: back to the page (a blocked member's Block refused), the second Ban saying it changed nothing
        foreach ($answers as $label => $answer) {
            $label === 'block again (refused)' ? $answer->assertForbidden() : $answer->assertRedirect();
        }
        $answers['ban a video banned already']->assertRedirect(route('admin.videos.index'));
        $this->assertSame(__('This video is already banned: nothing was changed.'), $said);

        // what the rows say, and what stayed as it was
        $this->assertSame('gina (#'.$owner->id.'), with their videos', $this->onlyRow('member.blocked')->details);
        $this->assertSame($blockedAt, $refusedAt, 'the block keeps its date');
        $this->assertSame($bannedAt, (string) $video->fresh()->banned_at, 'the ban keeps its date');
        $this->assertSame('Storm (#'.$other->id.')', $this->onlyRow('video.banned')->details);
        $this->assertSame('#'.$comment->id, $this->onlyRow('comment.hidden')->details);
        $this->assertSame('#'.$second->id, $this->onlyRow('report.resolved')->details, 'the report it closed');
        $this->assertSame([ReportStatus::BLOCKED, ReportStatus::BLOCKED], [$first->fresh()->status, $second->fresh()->status]);
        Event::assertDispatchedTimes(UserBanned::class, 1);
        Event::assertDispatchedTimes(VideoBanned::class, 1);
    }

    public function test_clearing_the_log_leaves_a_row_that_says_so(): void
    {
        DB::table('admin_audit_logs')->insert(['user_id' => null, 'action' => 'update.check', 'details' => 'current 1.2.3 (api)', 'created_at' => now()]);

        $this->actingAs($this->admin)->post(route('admin.audit.clear'))->assertRedirect();

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('audit.cleared', $rows[0]->action);
        $this->assertSame((int) $this->admin->id, (int) $rows[0]->user_id);
    }

    public function test_the_log_lists_the_new_rows_in_plain_words_in_both_admins_and_its_empty_state_stays(): void
    {
        foreach (['v2', 'v1'] as $ui) {
            config(['admin.ui' => $ui]);
            DB::table('admin_audit_logs')->delete();
            $html = (string) $this->actingAs($this->admin)->get(route('admin.audit.index'))->assertOk()->getContent();

            if ($ui === 'v2') {
                $this->assertStringContainsString('No admin actions logged', $html);
                $this->assertStringContainsString('Changes administrators make are written here.', $html);
            }

            $member = User::factory()->create(['username' => 'frank'.$ui]);
            $this->actingAs($this->admin)->post(route('admin.users.toggle-admin', $member));
            $html = (string) $this->actingAs($this->admin)->get(route('admin.audit.index'))->assertOk()->getContent();

            $this->assertStringContainsString('>'.e(AuditActions::LABELS['member.admin.granted']).'</', $html, "{$ui}: the label");
            $this->assertStringContainsString('title="member.admin.granted"', $html, "{$ui}: the key in the tooltip");
            $this->assertStringContainsString('frank'.$ui, $html, "{$ui}: the member");
            $this->assertStringContainsString('boss', $html, "{$ui}: who did it");
        }
    }
}
