<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AppVersion;
use App\Support\Updater\Downloader;
use App\Support\Updater\ReleaseCheck;
use App\Support\Updater\ShippedTexts;
use App\Support\Updater\TranslationMerge;
use App\Support\Updater\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Updater\Support\FakeDatabaseBackup;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\TestCase;

/**
 * Home after an upload by hand of a release without a database change (no Finish box): language files the owner changed
 * that still have texts this version corrected get a box of their own, "Correct the texts" (Updater::pendingTexts(), the
 * same as Tools > Updates), which corrects them and comes back to Home.
 */
class AdminHomeTextsToCorrectTest extends TestCase
{
    use RefreshDatabase;

    private UpdaterSandbox $box;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['admin.ui' => 'v2']);
        $shipped = ['Delete' => 'Entfernen', 'Home' => 'Start', 'Save' => 'Sichern'];
        $release = ['Delete' => 'Löschen', 'Home' => 'Start', 'Save' => 'Speichern'];
        $this->box = new UpdaterSandbox();
        $this->box->install(AppVersion::CURRENT, [
            'index.php' => "<?php // index.php\n",
            'lang/de.json' => TranslationMerge::encode($release),
            ShippedTexts::PATH => ShippedTexts::encodeData(ShippedTexts::build(['de' => $release], [['de' => $shipped]], ['1.2.0'], AppVersion::CURRENT)),
        ]);
        // The owner's German imported again after the upload: the old texts, one own text.
        $this->box->write(['lang/de.json' => TranslationMerge::encode(['Delete' => 'Entfernen', 'Home' => 'Start', 'Save' => 'Mein Speichern'])]);
        $this->app->instance(Updater::class, new Updater($this->box->paths(), new ReleaseCheck(), new FakeDatabaseBackup($this->box->root.'/storage/app/backups'), new Downloader(), AppVersion::CURRENT));
    }

    protected function tearDown(): void
    {
        $this->box->delete();

        parent::tearDown();
    }

    public function test_home_offers_to_correct_the_texts_and_comes_back_after_it(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'owner']);

        $this->actingAs($admin)->get(route('admin.index'))->assertOk()
            ->assertSee('Your language files have texts to correct')
            ->assertSee('Deutsch: 1 text to correct')
            ->assertSee('name="from" value="home"', false);

        $this->actingAs($admin)->post(route('admin.update.finish'), ['only' => 'texts', 'from' => 'home'])
            ->assertRedirect(route('admin.index'))
            ->assertSessionHas('success', 'Texts you never changed in your language files now have the corrected wording of this version (Deutsch: 1 text corrected, your own wording kept for 1 text this version corrects).');
        $this->assertSame(['Delete' => 'Löschen', 'Home' => 'Start', 'Save' => 'Mein Speichern'], TranslationMerge::decode((string) $this->box->read('lang/de.json')));

        $this->actingAs($admin)->get(route('admin.index'))->assertOk()->assertDontSee('Your language files have texts to correct');
    }

    /**
     * A language file whose only texts to correct are keys this version no longer has, still with the text a release
     * shipped (the data file's "removed" list, judge R3-026): Home and Tools > Updates offer them too, and "Correct the
     * texts" says what it did (not "no texts left to correct").
     */
    public function test_keys_this_version_no_longer_has_are_offered_and_their_removal_is_reported(): void
    {
        $this->installWithKeysThisVersionNoLongerHas();
        $admin = User::factory()->admin()->create(['username' => 'owner']);

        $this->assertSame(['de' => 1], app(Updater::class)->pendingTexts());
        // The box covers texts this version no longer uses too (review UP-RV-02).
        $this->actingAs($admin)->get(route('admin.index'))->assertOk()
            ->assertSee('Your language files have texts to correct')
            ->assertSee('This version corrected those texts or no longer uses them.')
            ->assertSee('Deutsch: 1 text to correct');

        // Nothing was corrected: the answer says what went, not "now have the corrected wording" (review UP-RV-02).
        $this->actingAs($admin)->post(route('admin.update.finish'), ['only' => 'texts', 'from' => 'home'])
            ->assertRedirect(route('admin.index'))
            ->assertSessionHas('success', 'Texts this version no longer uses were removed from your language files; your own texts stay (Deutsch: 1 text this version no longer uses removed).');
        $this->assertSame(['Delete' => 'Löschen', 'Old button' => 'Mein Knopf', 'Save' => 'Speichern'], TranslationMerge::decode((string) $this->box->read('lang/de.json')));
        $this->assertSame([], app(Updater::class)->pendingTexts(), 'nothing left');
    }

    /** The same on the command line (update by hand): its last line says what went too (review UP-RV-02). */
    public function test_the_command_says_which_texts_went_when_no_text_needed_a_correction(): void
    {
        $this->installWithKeysThisVersionNoLongerHas();

        $this->artisan('updates:texts')
            ->expectsOutputToContain('lang/de.json (Deutsch): 0 texts corrected, 1 text this version no longer uses removed')
            ->expectsOutputToContain('Done: no text needed a correction; 1 text this version no longer uses was removed.')
            ->doesntExpectOutputToContain('Done: no text needed a correction.')
            ->assertExitCode(0);
        $this->assertSame(['Delete' => 'Löschen', 'Old button' => 'Mein Knopf', 'Save' => 'Speichern'], TranslationMerge::decode((string) $this->box->read('lang/de.json')));

        // Again: nothing left.
        $this->artisan('updates:texts')->expectsOutputToContain('Done: no text needed a correction.')->assertExitCode(0);
    }

    /** A site whose German of 1.2.0 still has two keys this version no longer has: one with its shipped text, one the owner's. */
    private function installWithKeysThisVersionNoLongerHas(): void
    {
        $this->box->delete();
        $release = ['Delete' => 'Löschen', 'Save' => 'Speichern'];
        $older = ['Delete' => 'Löschen', 'Save' => 'Speichern', 'Old menu' => 'Altes Menü', 'Old button' => 'Alter Knopf'];
        $this->box = new UpdaterSandbox();
        $this->box->install(AppVersion::CURRENT, [
            'index.php' => "<?php // index.php\n",
            'lang/de.json' => TranslationMerge::encode($release),
            ShippedTexts::PATH => ShippedTexts::encodeData(ShippedTexts::build(['de' => $release], [['de' => $older]], ['1.2.0'], AppVersion::CURRENT, [['de' => $older]])),
        ]);
        // The owner's German of 1.2.0 imported again: the old key with its shipped text goes, the owner's own text stays.
        $this->box->write(['lang/de.json' => TranslationMerge::encode($release + ['Old menu' => 'Altes Menü', 'Old button' => 'Mein Knopf'])]);
        $this->app->instance(Updater::class, new Updater($this->box->paths(), new ReleaseCheck(), new FakeDatabaseBackup($this->box->root.'/storage/app/backups'), new Downloader(), AppVersion::CURRENT));
    }
}
