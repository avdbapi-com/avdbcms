<?php

namespace Tests\Feature\Updater;

use App\Support\Updater\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * C2 (1.2.2): the swap token ran out before the swap began, and a visitor's request made the maintenance hook stand
 * down (the site answers again, nothing was replaced). The administrator's Finish / Undo page of that hook may still be
 * open in a tab. Its click used to reach the site as a POST to whatever address the page had (a 405); now:
 *
 *  - the hook file stays, standing by: every request goes to the site, except that click, which goes to Tools > Updates
 *    (303, ?update_choice=finish|undo) with nothing run or moved;
 *  - Tools > Updates says what happened: after Finish the update (or undo) goes on by itself; after Undo nothing was
 *    replaced, so the page waits for Cancel (which puts the new built assets back and takes the hook away) or Continue;
 *  - the address alone changes nothing (no message, no hold) unless this administrator's plan really stood down;
 *  - the standing-by hook goes once the update cookie of its page ran out, or when the next step writes a new hook.
 */
class UpdateStandingHookTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    private const FINISH_UPDATE = 'Your site came back online before the update replaced any file, so nothing was changed. The update goes on now.';

    private const HOLD_UPDATE = 'Your site came back online before the update replaced any file, so nothing was changed. Cancel the update, or click Continue to install it.';

    private const FINISH_UNDO = 'Your site came back online before the undo put any file back, so nothing was changed. The undo goes on now.';

    private const HOLD_UNDO = 'Your site came back online before the undo put any file back, so it still runs the new version. Cancel the undo, or click Continue to go back.';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['admin.ui' => 'v2']);
        $this->makeSite();
    }

    protected function tearDown(): void
    {
        $this->removeSite();

        parent::tearDown();
    }

    public static function choices(): array
    {
        return ['Finish the update' => ['finish'], 'Undo the update' => ['undo']];
    }

    #[DataProvider('choices')]
    public function test_a_click_on_the_page_of_a_hook_that_stood_down_goes_to_tools_updates_and_runs_nothing(string $action): void
    {
        [$cookies, $formToken] = $this->standDownWithThePageOpen($this->driveToSwap());
        $before = $this->box->snapshot();
        $plan = $this->storedPlan();

        $answer = $this->hookRequest(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/updates', 'HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'text/html'],
            ['avdbcms_update' => $action, 'form_token' => $formToken], $cookies);

        $this->assertSame(303, $answer['status']);
        $this->assertSame(route('admin.updates.index').'?update_choice='.$action, $answer['headers']['Location']);
        $this->assertStringContainsString('no-store', $answer['headers']['Cache-Control']);
        $this->assertSame('', $answer['body']);
        $this->assertSame($plan, $this->storedPlan(), 'nothing ran');
        $this->assertSame($before, $this->box->snapshot(), 'nothing moved');
        $this->assertFileExists($this->box->paths()->hook());
        $this->assertFileDoesNotExist($this->box->paths()->down());

        // The same click from another browser (no update cookie) is sent the same way: the redirect changes nothing.
        $this->assertSame(303, $this->hookRequest(['REQUEST_METHOD' => 'POST'], ['avdbcms_update' => $action, 'form_token' => $formToken])['status']);

        // Anything else goes to the site.
        $this->assertNull($this->hookRequest(['REQUEST_METHOD' => 'GET', 'HTTP_ACCEPT' => 'text/html'], [], $cookies));
        $this->assertNull($this->hookRequest(['REQUEST_METHOD' => 'POST'], ['avdbcms_update' => 'finish']), 'not a form of the hook page');
        $this->assertNull($this->hookRequest(['REQUEST_METHOD' => 'POST'], ['name' => 'a form of the site']));
    }

    public function test_after_finish_tools_updates_says_so_and_the_update_goes_on_to_the_end(): void
    {
        $this->standDownWithThePageOpen($this->driveToSwap());
        $this->app->instance(Updater::class, $this->updater());

        $page = $this->actingAs($this->admin)->get(route('admin.updates.index', ['update_choice' => 'finish']))->assertOk();

        $page->assertSee(self::FINISH_UPDATE)->assertSee('data-ad-updates-choice', false);
        $boot = $this->boot($page);
        $this->assertTrue($boot['run'], 'the page goes on by itself');
        $this->assertSame('step', $boot['plan']['next']);

        // What the page then does: the next steps prepare the swap again (a new hook replaces the standing one).
        $ready = $this->stepUntil($this->updater(), fn (array $answer): bool => $answer['next'] !== 'step');
        $this->assertSame('swap', $ready['next']);
        $this->assertArrayNotHasKey('lapsed_at', $this->storedPlan());
        $this->assertSame('swapped', $this->swapThroughHook($ready['token'])['step']);
        $this->assertSame('done', $this->finishOn($this->updater($this->next))['status']);
    }

    public function test_after_undo_tools_updates_waits_and_cancel_leaves_the_site_as_it_was(): void
    {
        $this->standDownWithThePageOpen($this->driveToSwap());
        $this->app->instance(Updater::class, $this->updater());

        $page = $this->actingAs($this->admin)->get(route('admin.updates.index', ['update_choice' => 'undo']))->assertOk();

        $page->assertSee(self::HOLD_UPDATE);
        $boot = $this->boot($page);
        $this->assertFalse($boot['run'], 'the page does not go on with the update by itself');
        $this->assertMatchesRegularExpression('/<form method="POST" action="[^"]+\/admin\/updates\/step" data-ad-updates-continue="step"\s*>/', $page->getContent(), 'Continue is shown');
        $this->assertMatchesRegularExpression('/data-ad-updates-cancel[^>]*data-ad-confirm="Cancel the update\?"(?![^>]*\bhidden\b)[^>]*>/', $page->getContent(), 'Cancel is shown');

        // Cancel: the new built assets that were in already go back, the standing hook goes with the plan.
        $this->actingAs($this->admin)->postJson(route('admin.updates.cancel'))->assertOk()->assertJson(['status' => 'cancelled']);

        $this->assertSiteUnchanged('by an update cancelled after its hook stood down');
        $this->assertSame('cancelled', $this->storedPlan()['status']);
    }

    #[DataProvider('choices')]
    public function test_an_undo_whose_hook_stood_down_gets_the_undo_words(string $action): void
    {
        $this->updateToNext();
        $this->standDownWithThePageOpen($this->undoToSwap($this->updater($this->next)));
        $this->app->instance(Updater::class, $this->updater($this->next));
        $this->assertSame('undo', $this->storedPlan()['kind']);

        $page = $this->actingAs($this->admin)->get(route('admin.updates.index', ['update_choice' => $action]))->assertOk();

        $page->assertSee($action === 'finish' ? self::FINISH_UNDO : self::HOLD_UNDO);
        $this->assertSame($action === 'finish', $this->boot($page)['run']);
    }

    public function test_after_finish_an_update_that_stalled_meanwhile_waits_for_continue(): void
    {
        // The click came more than 30 minutes after the hook stood down: the update stalled, so it does not go on by
        // itself (the stalled-update callout offers Continue and Cancel); the note says so instead of "goes on now".
        $this->standDownWithThePageOpen($this->driveToSwap());
        $this->changePlan(['step_at' => time() - Updater::STALL_SECONDS - 60]);
        $this->app->instance(Updater::class, $this->updater());

        $page = $this->actingAs($this->admin)->get(route('admin.updates.index', ['update_choice' => 'finish']))->assertOk();

        $page->assertSee(self::HOLD_UPDATE)->assertDontSee(self::FINISH_UPDATE);
        $this->assertFalse($this->boot($page)['run']);
    }

    public function test_the_address_alone_changes_nothing(): void
    {
        $this->app->instance(Updater::class, $this->updater());

        // No update at all.
        $this->actingAs($this->admin)->get(route('admin.updates.index', ['update_choice' => 'undo']))->assertOk()
            ->assertDontSee('data-ad-updates-choice', false)->assertDontSee(self::HOLD_UPDATE);

        // An update that runs and never stood down: the page goes on as always.
        $this->driveToSwap();
        $this->changePlan(['status' => 'preparing']);
        $page = $this->actingAs($this->admin)->get(route('admin.updates.index', ['update_choice' => 'undo']))->assertOk();
        $page->assertDontSee('data-ad-updates-choice', false)->assertDontSee(self::HOLD_UPDATE);
        $this->assertTrue($this->boot($page)['run']);

        // Another administrator's update that stood down: not theirs to hold.
        $this->changePlan(['lapsed_at' => time()]);
        $other = $this->otherAdmin();
        $this->actingAs($other)->get(route('admin.updates.index', ['update_choice' => 'undo']))->assertOk()
            ->assertDontSee('data-ad-updates-choice', false);
    }

    public function test_a_standing_hook_goes_once_the_update_cookie_of_its_page_ran_out(): void
    {
        [, $formToken] = $this->standDownWithThePageOpen($this->driveToSwap());
        $this->changeHook("/'cookie_expires' => \\d+/", "'cookie_expires' => ".(time() - 1));

        $this->assertNull($this->hookRequest(['REQUEST_METHOD' => 'POST'], ['avdbcms_update' => 'finish', 'form_token' => $formToken]));
        $this->assertFileDoesNotExist($this->box->paths()->hook());
        $this->assertSame('preparing', $this->storedPlan()['status']);
    }

    /* ----------------------------------------------------------------- helpers */

    /**
     * The administrator's Finish / Undo page of the hook is opened (its form token kept), the swap token runs out, and a
     * visitor's request makes the hook stand down: the site answers again, nothing was replaced.
     *
     * @param  array{token: string, cookie: string}  $ready
     * @return array{0: array<string, string>, 1: string} the update cookie and the page's form token
     */
    private function standDownWithThePageOpen(array $ready): array
    {
        $cookies = [Updater::COOKIE => $ready['cookie']];
        $page = $this->hookRequest(['REQUEST_METHOD' => 'GET', 'HTTP_ACCEPT' => 'text/html'], [], $cookies);
        $this->assertSame(200, $page['status']);
        $this->assertSame(1, preg_match('/name="form_token" value="([0-9a-f]{64})"/', $page['body'], $match));

        $this->changeHook("/'token_expires' => \\d+/", "'token_expires' => ".(time() - 1));
        $this->assertNull($this->hookRequest(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']), 'a visitor gets the site again');
        $this->assertSame('preparing', $this->storedPlan()['status']);
        $this->assertIsInt($this->storedPlan()['lapsed_at']);

        return [$cookies, $match[1]];
    }

    /** Change a value of the hook's config (the hook reads time(), which a test cannot move: its stored times go back). */
    private function changeHook(string $pattern, string $replacement): void
    {
        $hook = $this->box->paths()->hook();
        $code = preg_replace($pattern, $replacement, (string) file_get_contents($hook), 1, $count);
        $this->assertSame(1, $count, 'the hook carries '.$pattern);
        file_put_contents($hook, $code);
    }

    /** @return array<string, mixed> the page's #ad-updates-boot */
    private function boot(TestResponse $page): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="ad-updates-boot">(.*?)</script>#s', (string) $page->getContent(), $match));

        return (array) json_decode($match[1], true);
    }
}
