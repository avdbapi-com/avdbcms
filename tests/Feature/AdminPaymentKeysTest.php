<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Money > Payment setup (owner decision OD1): saving a Stripe / PayPal key is protected like a payout change on a
 * payment dashboard. It needs the admin's own password (a wrong one answers 422 and writes nothing; wrong tries are
 * limited), keys are write-only ("Saved - ends in 4242" with Replace / Remove), every change names the key in the admin
 * activity log, and the value itself never appears in a response, a log, the session or a revision. The .env editor
 * stays behind the operational-tools switch.
 */
class AdminPaymentKeysTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private const ENV = "APP_NAME=Test\nSTRIPE_KEY=\nSTRIPE_SECRET=sk_test_OldSecretValue111\nCASHIER_CURRENCY=usd\nPAYPAL_MODE=sandbox\n";

    private const NEW_SECRET = 'sk_live_FAKEnotREAL4242';

    private string $directory;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2']);

        // A .env and a storage folder of the test's own: the backups must not land in the copy's real storage.
        $this->directory = storage_path('framework/testing/env-'.uniqid());
        File::ensureDirectoryExists($this->directory.'/storage/app');
        file_put_contents($this->directory.'/.env', self::ENV);
        $this->app->useEnvironmentPath($this->directory);
        $this->app->useStoragePath($this->directory.'/storage');

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    protected function tearDown(): void
    {
        unset($_ENV['STRIPE_SECRET'], $_ENV['PAYPAL_WEBHOOK_ID']);
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_a_key_is_saved_only_with_the_admins_own_password(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson(route('admin.money.setup.keys'), ['STRIPE_SECRET' => self::NEW_SECRET, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJson(['ok' => true, 'saved' => ['STRIPE_SECRET'], 'status' => ['STRIPE_SECRET' => true], 'ends' => ['STRIPE_SECRET' => '4242']])
            ->assertJsonMissingPath('password');

        $this->assertStringContainsString('STRIPE_SECRET='.self::NEW_SECRET."\n", $this->env());
        $this->assertCount(1, glob($this->directory.'/storage/app/env-backups/.env.*'), 'the previous file is kept');
    }

    public function test_a_wrong_or_missing_password_answers_422_and_writes_nothing(): void
    {
        $admin = $this->admin();

        foreach ([['password' => 'not-my-password'], ['password' => ''], []] as $attempt) {
            $response = $this->actingAs($admin)
                ->putJson(route('admin.money.setup.keys'), ['STRIPE_SECRET' => self::NEW_SECRET, 'CASHIER_CURRENCY' => 'eur'] + $attempt)
                ->assertStatus(422)
                ->assertJsonPath('ok', false)
                ->assertJsonValidationErrors(['password']);

            $this->assertSame(isset($attempt['password']) && $attempt['password'] !== '' ? 'That is not your password.' : 'Type your admin password to save payment keys.', $response->json('errors.password.0'));
        }

        $this->assertSame(self::ENV, $this->env(), 'the .env file is untouched');
        $this->assertSame([], glob($this->directory.'/storage/app/env-backups/.env.*') ?: [], 'no backup either');
        $this->assertSame(0, DB::table('admin_audit_logs')->count(), 'nothing changed, so nothing is logged');
    }

    public function test_removing_a_key_or_changing_the_currency_needs_the_password_too(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->putJson(route('admin.money.setup.keys'), ['clear' => ['STRIPE_SECRET']])->assertStatus(422);
        $this->actingAs($admin)->putJson(route('admin.money.setup.keys'), ['CASHIER_CURRENCY' => 'eur', 'PAYPAL_MODE' => 'live'])->assertStatus(422);
        $this->assertSame(self::ENV, $this->env());

        $this->actingAs($admin)
            ->putJson(route('admin.money.setup.keys'), ['clear' => ['STRIPE_SECRET'], 'CASHIER_CURRENCY' => 'EUR', 'PAYPAL_MODE' => 'live', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('saved', ['CASHIER_CURRENCY', 'PAYPAL_MODE', 'STRIPE_SECRET'])
            ->assertJsonPath('status.STRIPE_SECRET', false)
            ->assertJsonPath('ends.STRIPE_SECRET', null);

        $this->assertSame("APP_NAME=Test\nSTRIPE_KEY=\nSTRIPE_SECRET=\nCASHIER_CURRENCY=eur\nPAYPAL_MODE=live\n", $this->env());
    }

    public function test_an_unchanged_form_needs_no_password_but_a_typed_key_always_does(): void
    {
        $admin = $this->admin();

        // The form without JavaScript posts the currency and mode every time: nothing changes, nothing is asked.
        $this->actingAs($admin)
            ->putJson(route('admin.money.setup.keys'), ['STRIPE_KEY' => '', 'CASHIER_CURRENCY' => 'usd', 'PAYPAL_MODE' => 'sandbox'])
            ->assertOk()
            ->assertJsonPath('saved', [])
            ->assertJsonPath('message', 'Nothing to save.');

        // A typed key equal to the saved one changes nothing either, but the answer must not tell a stranger that it
        // matched: the password comes first.
        $this->actingAs($admin)
            ->putJson(route('admin.money.setup.keys'), ['STRIPE_SECRET' => 'sk_test_OldSecretValue111', 'password' => 'guess'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
        $this->actingAs($admin)
            ->putJson(route('admin.money.setup.keys'), ['STRIPE_SECRET' => 'sk_test_OldSecretValue111', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('saved', []);

        $this->assertSame(self::ENV, $this->env());
        $this->assertSame([], glob($this->directory.'/storage/app/env-backups/.env.*') ?: []);
    }

    public function test_every_change_names_the_key_in_the_admin_activity_log_and_never_its_value(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson(route('admin.money.setup.keys'), ['STRIPE_SECRET' => self::NEW_SECRET, 'PAYPAL_WEBHOOK_ID' => 'WH-7F3A9B21C', 'password' => self::PASSWORD])
            ->assertOk();
        $this->actingAs($admin)
            ->putJson(route('admin.money.setup.keys'), ['clear' => ['PAYPAL_WEBHOOK_ID'], 'password' => self::PASSWORD])
            ->assertOk();

        $rows = DB::table('admin_audit_logs')->orderBy('id')->get(['user_id', 'action', 'details', 'created_at'])->map(fn ($row): array => (array) $row)->all();

        $this->assertSame([
            ['money.payment_key.saved', 'STRIPE_SECRET'],
            ['money.payment_key.saved', 'PAYPAL_WEBHOOK_ID'],
            ['money.payment_key.removed', 'PAYPAL_WEBHOOK_ID'],
        ], array_map(fn (array $row): array => [$row['action'], $row['details']], $rows));

        foreach ($rows as $row) {
            $this->assertSame($admin->id, (int) $row['user_id'], 'who');
            $this->assertNotNull($row['created_at'], 'when');
        }

        $this->assertStringNotContainsString(self::NEW_SECRET, json_encode($rows));
        $this->assertStringNotContainsString('WH-7F3A9B21C', json_encode($rows));
    }

    public function test_the_page_shows_only_how_a_saved_key_ends_with_replace_and_remove(): void
    {
        $_ENV['STRIPE_SECRET'] = self::NEW_SECRET;
        $_ENV['PAYPAL_WEBHOOK_ID'] = 'WH-SHORT1';

        $html = $this->actingAs($this->admin())->get(route('admin.money.setup'))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::NEW_SECRET, $html);
        $this->assertStringNotContainsString('WH-SHORT1', $html);
        $this->assertStringContainsString('Saved - ends in '."\u{2066}4242\u{2069}", $html);
        // Replace and Remove name the key they act on (up to seven rows have them) and point at the row's state.
        $this->assertMatchesRegularExpression('/data-payment-key="STRIPE_SECRET">.*?id="pay-STRIPE_SECRET-status" data-key-status.*?data-key-replace hidden aria-label="Replace Secret key" aria-describedby="pay-STRIPE_SECRET-status">Replace<\/button>.*?<label class="ad-btn sm is-ghost ad-key-remove" data-key-clear ><input type="checkbox" name="clear\[\]" value="STRIPE_SECRET" aria-label="Remove Secret key" aria-describedby="pay-STRIPE_SECRET-status"> Remove<\/label>/s', $html);
        // A short value would give too much away: it only says "Saved".
        $this->assertMatchesRegularExpression('/data-payment-key="PAYPAL_WEBHOOK_ID">.*?data-saved-text="Saved">Saved<\/span>/s', $html);
        $this->assertMatchesRegularExpression('/data-payment-key="STRIPE_KEY">.*?data-saved-text="Not set">Not set<\/span>/s', $html);
        $this->assertStringContainsString('name="password" value="" autocomplete="current-password"', $html);
    }

    public function test_the_value_never_appears_in_a_response_the_log_the_session_or_a_revision(): void
    {
        $admin = $this->admin();
        $bodies = [];

        // Refused (wrong password, a malformed key), then saved: by fetch and as a plain form post.
        foreach ([
            ['STRIPE_SECRET' => self::NEW_SECRET, 'password' => 'wrong-password'],
            ['STRIPE_SECRET' => self::NEW_SECRET."\nAPP_DEBUG=true", 'password' => self::PASSWORD],
            ['STRIPE_SECRET' => self::NEW_SECRET, 'password' => self::PASSWORD],
        ] as $body) {
            $bodies[] = $this->actingAs($admin)->putJson(route('admin.money.setup.keys'), $body)->getContent();
            $bodies[] = $this->actingAs($admin)->from(route('admin.money.setup'))->put(route('admin.money.setup.keys'), $body)->getContent();
            $this->assertStringNotContainsString(self::NEW_SECRET, serialize(session()->all()), 'never flashed into the session');
            $this->assertStringNotContainsString(self::PASSWORD, serialize(session()->all()));
        }

        $_ENV['STRIPE_SECRET'] = self::NEW_SECRET;
        $bodies[] = $this->actingAs($admin)->get(route('admin.money.setup'))->getContent();
        $bodies[] = $this->actingAs($admin)->get(route('admin.money.index'))->getContent();
        $bodies[] = $this->actingAs($admin)->getJson(route('admin.settings.index', ['q' => 'stripe']))->getContent();

        foreach ($bodies as $body) {
            $this->assertStringNotContainsString(self::NEW_SECRET, $body);
            $this->assertStringNotContainsString('NewSecretValue', $body);
            $this->assertStringNotContainsString(self::PASSWORD, $body);
        }

        $this->assertStringContainsString('STRIPE_SECRET='.self::NEW_SECRET."\n", $this->env(), 'it was saved');
        $this->assertStringNotContainsString('APP_DEBUG', $this->env());

        $stored = json_encode([
            DB::table('admin_audit_logs')->get()->all(),
            DB::table('admin_setting_revisions')->get()->all(),
            DB::table('settings')->get()->all(),
            $this->logged,
        ]);
        $this->assertStringNotContainsString('NewSecretValue', $stored, 'not in the activity log, a revision, the settings or a log line');
        $this->assertStringNotContainsString(self::PASSWORD, $stored);
    }

    public function test_repeated_wrong_passwords_are_slowed_down_and_still_write_nothing(): void
    {
        $admin = $this->admin();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($admin)->putJson(route('admin.money.setup.keys'), ['STRIPE_SECRET' => self::NEW_SECRET, 'password' => 'nope'.$i])->assertStatus(422);
        }

        // The limit is shared with the maintenance link: guessing one way does not open the other.
        $this->actingAs($admin)
            ->putJson(route('admin.money.setup.keys'), ['STRIPE_SECRET' => self::NEW_SECRET, 'password' => self::PASSWORD])
            ->assertStatus(429)
            ->assertJsonPath('errors.password.0', 'Too many tries. Wait a minute and try again.');
        $this->actingAs($admin)->postJson(route('admin.settings.maintenance.reveal'), ['password' => self::PASSWORD])->assertStatus(429);

        $this->assertSame(self::ENV, $this->env());
    }

    public function test_the_env_editor_stays_behind_the_operational_tools_switch(): void
    {
        foreach (['admin.env-editor.index', 'admin.env-editor.update'] as $name) {
            $this->assertContains('admin.ops', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }

        // Payment setup is for every owner (no switch), protected by the password instead.
        $this->assertNotContains('admin.ops', Route::getRoutes()->getByName('admin.money.setup.keys')->gatherMiddleware());

        config(['admin.operational_tools_enabled' => false]);
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.env-editor.index'))->assertNotFound();
        $this->actingAs($admin)
            ->putJson(route('admin.money.setup.keys'), ['STRIPE_SECRET' => self::NEW_SECRET, 'password' => self::PASSWORD])
            ->assertOk();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['password' => self::PASSWORD]);
    }

    private function env(): string
    {
        return (string) file_get_contents($this->directory.'/.env');
    }
}
