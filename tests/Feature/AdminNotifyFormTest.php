<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\NotificationController;
use App\Models\User;
use App\Notifications\Support\NotificationText;
use App\Notifications\Support\NotificationTasks;
use App\Support\AdminPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Community > Send a notification has ONE form (judge R1-161): New notification on the Sent page, which sends in
 * batches at any size, with every choice the two forms had between them (every member, a confirmed or an unconfirmed
 * email, premium, chosen member numbers) and the same words; "Member numbers" only for Chosen members. The old
 * "To everyone" address opens that form; the old admin keeps its Mass Broadcast page; both addresses send the same way.
 */
class AdminNotifyFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        NotificationTasks::flushState();
        Cache::flush();
    }

    public function test_the_sent_page_holds_the_one_form_with_every_choice(): void
    {
        $admin = $this->admin('v2');
        User::factory()->count(3)->create();
        User::factory()->count(2)->create(['email_verified_at' => null]);

        $html = (string) $this->actingAs($admin)->get(route('admin.notifications.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'name="target"'), 'one form to send');
        $this->assertSame(1, substr_count($html, 'action="'.route('admin.notifications.broadcast', [], false).'"'));
        $this->assertStringNotContainsString(route('admin.broadcast.send', [], false), $html, 'no second form');
        $total = User::query()->count();
        $verified = User::query()->whereNotNull('email_verified_at')->count();
        foreach ([
            'all' => __('Every member (:count)', ['count' => number_format($total)]),
            'verified' => __('Members with a confirmed email (:count)', ['count' => number_format($verified)]),
            'unverified' => __('Members who did not confirm their email (:count)', ['count' => number_format($total - $verified)]),
            'premium' => __('Premium members (:count)', ['count' => 0]),
            'specific' => __('Chosen members'),
        ] as $value => $label) {
            $this->assertMatchesRegularExpression('#<option value="'.$value.'"[^>]*>'.preg_quote(e($label), '#').'</option>#', $html, $value);
        }
        // "Member numbers": its own field, shown only while "Chosen members" is picked (the form's class, admin.scss)
        $this->assertMatchesRegularExpression('#<form [^>]*class="[^"]*ad-notify-form[^"]*"#', $html);
        $this->assertMatchesRegularExpression('#<div class="ad-field ad-notify-chosen" data-ad-field="user_ids">#', $html);
        $this->assertStringContainsString(e(__('Only for Chosen members: their numbers, separated by commas, for example 1, 2, 5.')), $html);
        // the numbers run left to right in every language (an Arabic page showed "1, 2, 5" as "5 ,2 ,1"), the help is read
        $this->assertMatchesRegularExpression('#<input class="ad-input" id="user_ids" name="user_ids" value="" placeholder="1, 2, 5" dir="ltr"[^>]* aria-describedby="user_ids-help"\s*>#', $html);
        $this->assertStringContainsString('<p class="ad-help" id="user_ids-help">', $html);
        $this->assertStringContainsString('maxlength="5000"', $html);
        // the form is folded once something was sent, open when the old address (or ?new=1) asks for it
        $this->assertMatchesRegularExpression('#<details class="ad-panel ad-console-block ad-fold" id="new-notification"\s+open\s*>#', $html, 'nothing sent yet: open');
    }

    public function test_the_old_to_everyone_address_opens_the_one_form(): void
    {
        $admin = $this->admin('v2');
        $member = User::factory()->create();
        DB::table('notifications')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => NotificationText::BROADCAST_TYPE,
            'notifiable_type' => $member->getMorphClass(), 'notifiable_id' => $member->id, 'data' => json_encode(['title' => 'Big news', 'message' => 'Hi']),
            'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin)->get(route('admin.broadcast.index'))
            ->assertRedirect(route('admin.notifications.index', ['new' => 1]).'#new-notification');

        $folded = (string) $this->actingAs($admin)->get(route('admin.notifications.index'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('#id="new-notification"\s+open#', $folded, 'something was sent: folded');
        $opened = (string) $this->actingAs($admin)->get(route('admin.notifications.index', ['new' => 1]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#id="new-notification"\s+open#', $opened, '?new=1 opens it');
        $this->assertStringNotContainsString(e(__('To everyone')), $opened, 'no "To everyone" tab any more');
    }

    public function test_chosen_members_and_every_choice_go_out_from_the_one_form(): void
    {
        $admin = $this->admin('v2');
        $chosen = User::factory()->count(2)->create();
        $other = User::factory()->create(['email_verified_at' => null]);
        $ids = $chosen->pluck('id')->implode(', ');

        $this->actingAs($admin)->post(route('admin.notifications.broadcast'), ['target' => 'specific', 'user_ids' => $ids.', x, 999999', 'title' => 'For you', 'message' => 'Hello'])
            ->assertRedirect(route('admin.notifications.index'))
            ->assertSessionHas('success', trans_choice('{1} Sent to 1 member.|[2,*] Sent to :count members.', 2, ['count' => 2]));
        $this->assertSame($chosen->pluck('id')->sort()->values()->all(), $this->recipients('For you'));

        $this->actingAs($admin)->post(route('admin.notifications.broadcast'), ['target' => 'unverified', 'title' => 'Confirm', 'message' => 'Please'])->assertRedirect();
        $this->assertSame([$other->id], $this->recipients('Confirm'));

        $this->actingAs($admin)->post(route('admin.notifications.broadcast'), ['target' => 'verified', 'title' => 'Thanks', 'message' => str_repeat('a', 5000)])->assertRedirect();
        $this->assertNotContains($other->id, $this->recipients('Thanks'));
        $this->assertContains($chosen->first()->id, $this->recipients('Thanks'));

        $this->actingAs($admin)->post(route('admin.notifications.broadcast'), ['target' => 'specific', 'user_ids' => '', 'title' => 'Nobody', 'message' => 'x'])
            ->assertSessionHas('success', __('No member matches this choice, so nothing was sent.'));
        $this->actingAs($admin)->from(route('admin.notifications.index'))
            ->post(route('admin.notifications.broadcast'), ['target' => 'all', 'title' => str_repeat('t', 256), 'message' => 'x'])
            ->assertSessionHasErrors('title');
        $this->actingAs($admin)->from(route('admin.notifications.index'))
            ->post(route('admin.notifications.broadcast'), ['target' => 'nobody', 'title' => 't', 'message' => 'x'])
            ->assertSessionHasErrors('target');
    }

    public function test_member_numbers_are_read_as_the_admins_keyboard_writes_them(): void
    {
        // the Arabic help's own example "1، 2، 5" (Arabic commas), Arabic-Indic, Persian, Devanagari, Thai and full-width
        // digits, the ideographic comma, spaces and new lines, the "#" the list shows; no number in "x", "12a", "1-5"
        $this->assertSame([1, 2, 5], NotificationController::memberNumbers('1, 2, 5'));
        $this->assertSame([1, 2, 5], NotificationController::memberNumbers('1، 2، 5'));
        $this->assertSame([12, 3, 45], NotificationController::memberNumbers('١٢،٣ ٤٥'));
        $this->assertSame([12, 3], NotificationController::memberNumbers('۱۲؛۳'));
        $this->assertSame([12, 3], NotificationController::memberNumbers('१२, ३'));
        $this->assertSame([12, 3], NotificationController::memberNumbers('๑๒;๓'));
        $this->assertSame([1, 2, 7], NotificationController::memberNumbers("１、２\n#7\u{200F}"));
        $this->assertSame([7], NotificationController::memberNumbers('7, 7, #7, ٧'));
        $this->assertSame([], NotificationController::memberNumbers('x, 12a, 1-5, 0, -3, #, 9999999999999999999'));
        $this->assertCount(5000, NotificationController::memberNumbers(implode(', ', range(1, 6000))));

        $admin = $this->admin('v2');
        $members = User::factory()->count(4)->create();
        [$a, $b, $c, $d] = $members->pluck('id')->all();
        $arabic = fn (int $id): string => strtr((string) $id, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
        $typed = $arabic($a).'، '.$b."\n#".$c.'，'.$d."\u{200F}; x, 12a, 1-".$d.', '.$arabic($a);

        $this->actingAs($admin)->post(route('admin.notifications.broadcast'), ['target' => 'specific', 'user_ids' => $typed, 'title' => 'Typed', 'message' => 'Hello'])
            ->assertRedirect(route('admin.notifications.index'))
            ->assertSessionHas('success', trans_choice('{1} Sent to 1 member.|[2,*] Sent to :count members.', 4, ['count' => 4]));
        $this->assertSame(collect([$a, $b, $c, $d])->sort()->values()->all(), $this->recipients('Typed'), 'the four members, each once');
        $this->assertNotContains($admin->id, $this->recipients('Typed'), '"1-'.$d.'" is no list of numbers');
    }

    public function test_the_old_send_address_sends_the_same_way_and_the_old_admin_keeps_its_page(): void
    {
        $member = User::factory()->create();

        $v2 = $this->admin('v2');
        $this->actingAs($v2)->post(route('admin.broadcast.send'), ['target' => 'specific', 'user_ids' => (string) $member->id, 'title' => 'Old tab', 'message' => 'Still open'])
            ->assertRedirect(route('admin.notifications.index'));
        $this->assertSame([$member->id], $this->recipients('Old tab'));

        $v1 = $this->admin('v1');
        $page = (string) $this->actingAs($v1)->get(route('admin.broadcast.index'))->assertOk()->getContent();
        $this->assertStringContainsString('action="'.route('admin.broadcast.send').'"', $page, 'the old admin keeps its Mass Broadcast page');
        $this->actingAs($v1)->post(route('admin.broadcast.send'), ['target' => 'all', 'title' => 'Old admin', 'message' => 'Everyone'])
            ->assertRedirect(route('admin.broadcast.index'));
        $this->assertContains($member->id, $this->recipients('Old admin'));
    }

    // ------------------------------------------------------------------ helpers

    private function admin(string $ui): User
    {
        config(['admin.ui' => $ui]);
        $admin = User::factory()->admin()->create();
        AdminPreferences::set($admin, 'admin_ui', $ui);

        return $admin;
    }

    /** @return list<int> the members a notification with this title went to */
    private function recipients(string $title): array
    {
        return DB::table('notifications')->get(['notifiable_id', 'data'])
            ->filter(fn (object $row): bool => (json_decode((string) $row->data, true)['title'] ?? null) === $title)
            ->map(fn (object $row): int => (int) $row->notifiable_id)->sort()->values()->all();
    }
}
