<?php

namespace Tests\Feature\Updater;

use App\Models\User;
use App\Support\AppVersion;
use App\Support\Updater\Downloader;
use App\Support\Updater\PlanStore;
use App\Support\Updater\ReleaseCheck;
use App\Support\Updater\ShippedTexts;
use App\Support\Updater\TranslationMerge;
use App\Support\Updater\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Updater\Support\FakeDatabaseBackup;
use Tests\Feature\Updater\Support\Updater120;
use Tests\Feature\Updater\Support\Updater121;
use Tests\Feature\Updater\Support\Updater122;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * A language file the owner edited gets the texts a release corrected (1.2.3). The update keeps such a file
 * (the version before merges it: the owner's text wins, the release's new keys come in), so on its own it never got a
 * corrected text (178 in German for 1.2.2). Now Finish (the new version's code) gives every lang/<locale>.json the
 * owner changed the release's text wherever it still has exactly an older release's text (app/Support/Updater/
 * shipped-texts.json); the owner's own texts and keys stay.
 *
 *  - with the steps up to the swap run by this version's code and by the code 1.2.0, 1.2.1 and 1.2.2 shipped (Finish is
 *    always the new code): the corrected texts in a merged file and in one the merge left alone, the owner's texts and
 *    keys kept, a file as shipped not touched, per language in the plan, the result page and the admin log;
 *  - Undo puts every language file back exactly as the owner had it before the update;
 *  - a Finish the host cut off after it wrote a file counts that file once and keeps Undo exact;
 *  - a data file made for another version is not used;
 *  - Home's Finish update after an upload by hand does the same (no update backup there), once;
 *  - after an upload by hand without a Finish box (a release without a database change) or a Finish that found the
 *    update lock taken: Tools > Updates lists the files with texts to correct and corrects them with one click, and
 *    `php artisan updates:texts` does it on the command line.
 */
class UpdateTranslationsTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario {
        shippedFiles as private scenarioShippedFiles;
        releaseFiles as private scenarioReleaseFiles;
    }

    /** German as the installed version shipped it (the old texts). */
    private const DE_SHIPPED = ['Delete' => 'Entfernen', 'Home' => 'Start', 'Save' => 'Sichern', 'Upload' => 'Upload'];

    /** The owner's German: two old texts never touched, one own text, one own key. */
    private const DE_OWNER = ['Delete' => 'Entfernen', 'Home' => 'Start', 'Mein Schlüssel' => 'Mein Text', 'Save' => 'Sichern', 'Upload' => 'Datei senden'];

    /** German of the release: three texts corrected, one new key. */
    private const DE_RELEASE = ['Delete' => 'Löschen', 'Home' => 'Start', 'New' => 'Neu', 'Save' => 'Speichern', 'Upload' => 'Hochladen'];

    /** What the owner's German is after the update: the corrected texts, the new key, the owner's text and key. */
    private const DE_AFTER = ['Delete' => 'Löschen', 'Home' => 'Start', 'Mein Schlüssel' => 'Mein Text', 'New' => 'Neu', 'Save' => 'Speichern', 'Upload' => 'Datei senden'];

    /**
     * Spanish: the release only corrects texts (no new key), so the merge leaves the owner's file alone. The owner kept
     * the old text of Save (corrected), wrote their own for Search (a corrected text: kept, and counted) and for Home (a
     * text the release did not change: the owner's, not counted).
     */
    private const ES_SHIPPED = ['Home' => 'Inicio', 'Save' => 'Salvar', 'Search' => 'Busca'];

    private const ES_OWNER = ['Home' => 'Portada', 'Save' => 'Salvar', 'Search' => 'Encontrar'];

    private const ES_RELEASE = ['Home' => 'Inicio', 'Save' => 'Guardar', 'Search' => 'Buscar'];

    /** French: never edited by the owner (the release's file replaces it whole). */
    private const FR_SHIPPED = ['Home' => 'Accueil', 'Save' => 'Sauver'];

    private const FR_RELEASE = ['Home' => 'Accueil', 'New' => 'Nouveau', 'Save' => 'Enregistrer'];

    /** The version the release's data file is made for (null: the release itself). */
    private static ?string $dataFor = null;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        self::$dataFor = null;
    }

    protected function tearDown(): void
    {
        $this->removeSite();

        parent::tearDown();
    }

    /** @return array<string, string> */
    protected static function shippedFiles(): array
    {
        return self::scenarioShippedFiles() + [
            'lang/de.json' => TranslationMerge::encode(self::DE_SHIPPED),
            'lang/es.json' => TranslationMerge::encode(self::ES_SHIPPED),
            'lang/fr.json' => TranslationMerge::encode(self::FR_SHIPPED),
        ];
    }

    /** @return array<string, string> the release: its language files and the data file made from the installed ones */
    protected static function releaseFiles(): array
    {
        $release = ['de' => self::DE_RELEASE, 'es' => self::ES_RELEASE, 'fr' => self::FR_RELEASE];
        $data = ShippedTexts::build($release, [['de' => self::DE_SHIPPED, 'es' => self::ES_SHIPPED, 'fr' => self::FR_SHIPPED]], [AppVersion::CURRENT], self::$dataFor ?? UpdaterSandbox::nextVersion());

        return self::scenarioReleaseFiles() + [
            'lang/de.json' => TranslationMerge::encode(self::DE_RELEASE),
            'lang/es.json' => TranslationMerge::encode(self::ES_RELEASE),
            'lang/fr.json' => TranslationMerge::encode(self::FR_RELEASE),
            ShippedTexts::PATH => ShippedTexts::encodeData($data),
        ];
    }

    public static function oldCode(): array
    {
        return ['steps of this version' => ['current'], 'steps of 1.2.0' => ['1.2.0'], 'steps of 1.2.1' => ['1.2.1'], 'steps of 1.2.2' => ['1.2.2']];
    }

    #[DataProvider('oldCode')]
    public function test_finish_gives_edited_language_files_the_corrected_texts_and_undo_puts_them_back(string $code): void
    {
        $this->site();
        $old = $this->oldUpdater($code);
        $this->assertSame('swapped', $this->swapThroughHook($this->toSwap($old))['step']);

        // What the version before left for Finish: the owner's German merged (its texts, the new key), Spanish as the
        // owner had it, French replaced whole.
        $this->assertSame(self::sorted(self::DE_OWNER + ['New' => 'Neu']), $this->language('de'));
        $this->assertSame(self::ES_OWNER, $this->language('es'));
        $this->assertSame(self::FR_RELEASE, $this->language('fr'));
        $plan = $this->storedPlan();
        $this->assertSame([['path' => 'lang/de.json', 'added' => 1, 'kept' => 3]], $plan['report']['merged']);
        $this->assertArrayNotHasKey('texts', $plan);

        $done = $this->finishOn($this->updater($this->next));

        $this->assertSame('done', $done['status'], (string) $done['message']);
        // The corrected texts where the owner's file still had the old ones; the owner's own text and key stay.
        $this->assertSame(self::DE_AFTER, $this->language('de'));
        $this->assertSame(['Home' => 'Portada', 'Save' => 'Guardar', 'Search' => 'Encontrar'], $this->language('es'));
        $this->assertSame(TranslationMerge::encode(self::FR_RELEASE), $this->box->read('lang/fr.json'), 'a file as shipped is not touched');
        $this->assertSame(['de' => ['updated' => 2, 'kept' => 1, 'removed' => 0], 'es' => ['updated' => 1, 'kept' => 1, 'removed' => 0]], $this->storedPlan()['texts']);
        $this->assertContains('texts', $this->storedPlan()['finish_steps']);

        // Tools > Logs > Admin actions: one row per language.
        $this->assertSame(['update.started', 'update.database_backup', 'update.files', 'update.texts', 'update.texts', 'update.finished'], array_column($this->auditRows(), 'action'));
        $this->assertSame([
            'lang/de.json (Deutsch): 2 texts corrected to the wording of this version, your own wording kept for 1 text this version corrects',
            'lang/es.json (Español): 1 text corrected to the wording of this version, your own wording kept for 1 text this version corrects',
        ], array_column(array_slice($this->auditRows(), 3, 2), 'details'));

        // The step log says it too.
        $log = implode("\n", $this->updater($this->next)->logLines(null, 500));
        $this->assertStringContainsString("lang/de.json: 2 texts corrected to the wording of {$this->next}, the owner's own wording kept for 1 text it corrects", $log);

        // The result on Tools > Updates, per language.
        $this->app->instance(Updater::class, $this->updater($this->next));
        config(['admin.ui' => 'v2']);
        $html = $this->actingAs($this->admin)->get(route('admin.updates.index'))->assertOk()->getContent();
        $this->assertStringContainsString('These language files had texts you changed. Your texts stay; the new texts were added.', $html);
        $this->assertStringContainsString('Texts you never changed now have the corrected wording of this version.', $html);
        $this->assertStringContainsString('<code>lang/de.json</code> <span>Deutsch</span> <span class="ad-muted">1 new text added · 2 texts corrected · your own wording kept for 1 text this version corrects</span>', $html);
        $this->assertStringContainsString('<code>lang/es.json</code> <span>Español</span> <span class="ad-muted">1 text corrected · your own wording kept for 1 text this version corrects</span>', $html);
        $this->assertStringNotContainsString('<code>lang/fr.json</code>', $html);

        // The update backup holds both of the owner's files as they were before the update, and says what they are now.
        $backup = PlanStore::read($this->box->paths()->backups((string) $this->storedPlan()['backup_id']).'/backup.json');
        $this->assertSame('complete', $backup['status']);
        $entries = array_column($backup['entries'], null, 'path');
        $this->assertSame([hash('sha256', TranslationMerge::encode(self::DE_OWNER)), hash('sha256', (string) $this->box->read('lang/de.json'))], [$entries['lang/de.json']['old'], $entries['lang/de.json']['new']]);
        $this->assertSame(['merged', hash('sha256', TranslationMerge::encode(self::ES_OWNER)), hash('sha256', (string) $this->box->read('lang/es.json'))], [$entries['lang/es.json']['action'], $entries['lang/es.json']['old'], $entries['lang/es.json']['new']]);

        // Undo (this version's steps, the old version finishes): every language file exactly as the owner had it.
        $undo = $this->undoToSwap($this->updater($this->next));
        $this->assertSame([], $this->storedPlan()['report']['kept'], 'no file is left alone as "changed after the update"');
        $this->assertSame('swapped', $this->swapThroughHook($undo['token'])['step']);
        $finished = ['next' => 'finish'];

        for ($i = 0; $i < 10 && $finished['next'] === 'finish'; $i++) {
            $finished = $old->finish($this->admin);
        }

        $this->assertSame('undone', $finished['status'], (string) ($finished['message'] ?? ''));
        $this->assertSame(TranslationMerge::encode(self::DE_OWNER), $this->box->read('lang/de.json'));
        $this->assertSame(TranslationMerge::encode(self::ES_OWNER), $this->box->read('lang/es.json'));
        $this->assertSame(TranslationMerge::encode(self::FR_SHIPPED), $this->box->read('lang/fr.json'));
        $this->assertNull($this->box->read(ShippedTexts::PATH), 'the data file came with the update and went with it');
        $this->assertSame($this->withoutCompiledCaches($this->before), $this->box->snapshot(), 'every file as before the update');
    }

    public function test_a_finish_cut_off_after_it_wrote_a_language_file_counts_it_once_and_keeps_undo_exact(): void
    {
        $this->site();
        $this->assertSame('swapped', $this->swapThroughHook($this->driveToSwap()['token'])['step']);

        // The Finish the host killed: its database step done, the German file corrected and written, the plan not told.
        $texts = ShippedTexts::load($this->box->root.'/'.ShippedTexts::PATH, $this->next);
        $result = ShippedTexts::correctFile($this->box->root.'/lang/de.json', $texts['de']);
        $this->assertSame([2, 1], [$result['updated'], $result['kept']]);
        file_put_contents($this->box->root.'/lang/de.json', $result['text']);
        $this->changePlan(['status' => 'migrating', 'migrations' => 0, 'texts' => ['de' => [
            'updated' => 2, 'kept' => 1, 'writing' => true, 'from' => hash('sha256', $result['before']), 'to' => hash('sha256', $result['text']),
        ]]]);

        $done = $this->finishOn($this->updater($this->next));

        $this->assertSame('done', $done['status'], (string) $done['message']);
        $this->assertSame(self::DE_AFTER, $this->language('de'));
        $this->assertSame(['de' => ['updated' => 2, 'kept' => 1, 'removed' => 0], 'es' => ['updated' => 1, 'kept' => 1, 'removed' => 0]], $this->storedPlan()['texts'], 'counted once, with the counts of the cut-off Finish');
        $this->assertSame(2, collect($this->auditRows())->where('action', 'update.texts')->count());
        $backup = PlanStore::read($this->box->paths()->backups((string) $this->storedPlan()['backup_id']).'/backup.json');
        $this->assertSame(hash('sha256', (string) $this->box->read('lang/de.json')), array_column($backup['entries'], null, 'path')['lang/de.json']['new']);

        // Finish once more (its answer was lost): nothing changes.
        $tree = $this->box->snapshot();
        $rows = $this->auditRows();
        $this->assertSame('done', $this->updater($this->next)->finish($this->admin)['status']);
        $this->assertSame([$tree, $rows], [$this->box->snapshot(), $this->auditRows()]);

        // Undo still puts the owner's German back.
        $undo = $this->undoToSwap($this->updater($this->next));
        $this->assertSame('swapped', $this->swapThroughHook($undo['token'])['step']);
        $this->assertSame('undone', $this->finishOn($this->updater())['status']);
        $this->assertSame(TranslationMerge::encode(self::DE_OWNER), $this->box->read('lang/de.json'));
    }

    public function test_a_file_changed_between_the_swap_and_finish_is_corrected_but_left_alone_by_undo(): void
    {
        $this->site();
        $this->assertSame('swapped', $this->swapThroughHook($this->driveToSwap()['token'])['step']);

        // The owner edits German on Settings > Languages right after the swap, before Finish (one of the old texts).
        $this->box->write(['lang/de.json' => TranslationMerge::encode(['Upload' => 'Hochladen!'] + self::DE_OWNER + ['New' => 'Neu'])]);

        $this->assertSame('done', $this->finishOn($this->updater($this->next))['status']);
        $this->assertSame(self::sorted(['Upload' => 'Hochladen!'] + self::DE_AFTER), $this->language('de'));

        // Undo does not bring back the file from before the update over that edit: it lists it as changed after it.
        $this->undoToSwap($this->updater($this->next));
        $this->assertSame(['lang/de.json'], $this->storedPlan()['report']['kept']);
    }

    public function test_a_data_file_made_for_another_version_is_not_used(): void
    {
        self::$dataFor = '9.9.9';
        $this->site();
        $this->assertSame('swapped', $this->swapThroughHook($this->driveToSwap()['token'])['step']);

        $this->assertSame('done', $this->finishOn($this->updater($this->next))['status']);

        $this->assertSame(self::sorted(self::DE_OWNER + ['New' => 'Neu']), $this->language('de'), 'merged only: the owner\'s file as the version before left it');
        $this->assertSame(self::ES_OWNER, $this->language('es'));
        $this->assertArrayNotHasKey('texts', $this->storedPlan());
        $this->assertSame([], collect($this->auditRows())->where('action', 'update.texts')->all());
    }

    public function test_finish_update_on_home_after_an_upload_by_hand_corrects_the_texts_once(): void
    {
        // A site that runs the new version, uploaded by hand: the release's files (and their manifest) are in place, the
        // owner's German is the file they saved before the upload and imported again (the old texts, their own).
        $this->handSite();
        $admin = User::factory()->admin()->create(['username' => 'owner']);
        $german = $this->box->root.'/lang/de.json';

        $first = $this->actingAs($admin)->post(route('admin.update.finish'));

        $first->assertRedirect(route('admin.index'))->assertSessionHas('success',
            'Your site is up to date. Texts you never changed in your language files now have the corrected wording of this version (Deutsch: 2 texts corrected, your own wording kept for 1 text this version corrects).');
        $this->assertSame(['Delete' => 'Löschen', 'Home' => 'Start', 'Mein Schlüssel' => 'Mein Text', 'Save' => 'Speichern', 'Upload' => 'Datei senden'], TranslationMerge::decode((string) file_get_contents($german)));
        $this->assertSame(TranslationMerge::encode(self::FR_RELEASE), $this->box->read('lang/fr.json'), 'a file as shipped is not touched');
        $rows = DB::table('admin_audit_logs')->where('action', 'update.texts')->get(['details', 'user_id']);
        $this->assertSame([['lang/de.json (Deutsch): 2 texts corrected to the wording of this version, your own wording kept for 1 text this version corrects', (int) $admin->getKey()]],
            $rows->map(fn (object $row): array => [$row->details, (int) $row->user_id])->all());

        // Again (or another administrator): nothing left to correct, no new row, the plain message.
        $tree = $this->box->snapshot();
        $this->actingAs($admin)->postJson(route('admin.update.finish'))->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Your site is up to date.'])
            ->assertJsonPath('texts.de', ['updated' => 0, 'kept' => 1, 'removed' => 0, 'written' => true]);
        $this->assertSame($tree, $this->box->snapshot());
        $this->assertSame(1, DB::table('admin_audit_logs')->where('action', 'update.texts')->count());
    }

    public function test_finish_update_on_home_leaves_the_texts_to_a_running_one_click_update_and_never_touches_a_working_copy(): void
    {
        $this->handSite();
        $admin = User::factory()->admin()->create(['username' => 'owner']);
        $tree = $this->box->snapshot();

        // A one-click update runs: its own Finish corrects the texts, Home's does not (and says nothing of them);
        // "Correct the texts" says they were not checked.
        PlanStore::write($this->box->paths()->plan(), ['status' => 'downloading', 'kind' => 'update', 'version' => '9.9.9', 'admin_id' => 1, 'step_at' => time(), 'started_at' => time()]);
        $this->actingAs($admin)->post(route('admin.update.finish'))->assertSessionHas('success', 'Your site is up to date.');
        $this->actingAs($admin)->post(route('admin.update.finish'), ['only' => 'texts'])->assertRedirect(route('admin.updates.index'))
            ->assertSessionHasErrors(['update' => 'Your language files were not checked this time, because another update step was running. Try again in a minute on Tools > Updates.']);
        $this->assertSame($tree, $this->box->snapshot());

        // A copy of the code without the installed list of its files (a working copy, not an installed site): never.
        File::delete($this->box->paths()->plan());
        File::delete($this->box->paths()->manifest());
        $this->actingAs($admin)->post(route('admin.update.finish'))->assertSessionHas('success', 'Your site is up to date.');
        $this->assertSame(array_diff_key($tree, ['avdbcms-manifest.json' => true]), $this->box->snapshot());

        // The project itself (no updater bound: base_path()): its own language files stay byte for byte, and nothing is
        // written in its storage either (not even the update lock).
        $this->app->forgetInstance(Updater::class);
        $own = array_map(fn (string $file): string => (string) hash_file('sha256', $file), ShippedTexts::files(base_path()));
        $lock = (new Updater())->paths()->lock();
        $locked = is_file($lock) ? (int) filemtime($lock) : null;
        $this->actingAs($admin)->post(route('admin.update.finish'))->assertSessionHas('success');
        $this->assertSame($own, array_map(fn (string $file): string => (string) hash_file('sha256', $file), ShippedTexts::files(base_path())));
        clearstatcache();
        $this->assertSame($locked, is_file($lock) ? (int) filemtime($lock) : null);
    }

    /**
     * A release without a database change, uploaded by hand: Home has no Finish box. Tools > Updates lists the language
     * files with texts to correct and corrects them with one click (the texts alone, no database step); the page comes
     * back, says what it did and offers nothing more.
     */
    public function test_tools_updates_offers_to_correct_the_texts_after_an_upload_by_hand(): void
    {
        $this->handSite();
        $admin = User::factory()->admin()->create(['username' => 'owner']);
        config(['admin.ui' => 'v2']);

        $html = $this->actingAs($admin)->get(route('admin.updates.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Your language files have texts to correct', $html);
        $this->assertStringContainsString('<code>lang/de.json</code> <span>Deutsch</span> <span class="ad-muted">2 texts to correct</span>', $html);
        $this->assertStringNotContainsString('<code>lang/fr.json</code>', $html, 'a file as shipped has nothing to correct');
        $this->assertMatchesRegularExpression('#<form method="POST" action="'.preg_quote(route('admin.update.finish'), '#').'"[^>]*>\s*<input type="hidden" name="_token"[^>]*>\s*<input type="hidden" name="only" value="texts">#', $html);

        $this->actingAs($admin)->post(route('admin.update.finish'), ['only' => 'texts'])
            ->assertRedirect(route('admin.updates.index'))
            ->assertSessionHas('success', 'Texts you never changed in your language files now have the corrected wording of this version (Deutsch: 2 texts corrected, your own wording kept for 1 text this version corrects).');
        $this->assertSame(['Delete' => 'Löschen', 'Home' => 'Start', 'Mein Schlüssel' => 'Mein Text', 'Save' => 'Speichern', 'Upload' => 'Datei senden'], $this->language('de'));
        $this->assertSame(['lang/de.json (Deutsch): 2 texts corrected to the wording of this version, your own wording kept for 1 text this version corrects'],
            DB::table('admin_audit_logs')->where('action', 'update.texts')->pluck('details')->all());

        // Done: the page offers nothing more, and a second click (another tab) says so.
        $this->assertStringNotContainsString('Your language files have texts to correct', $this->actingAs($admin)->get(route('admin.updates.index'))->getContent());
        $this->actingAs($admin)->post(route('admin.update.finish'), ['only' => 'texts'])
            ->assertRedirect(route('admin.updates.index'))->assertSessionHas('success', 'Your language files have no texts left to correct.');
        $this->actingAs($admin)->postJson(route('admin.update.finish'), ['only' => 'texts'])->assertOk()
            ->assertJson(['ok' => true, 'texts_state' => 'done', 'message' => 'Your language files have no texts left to correct.']);
        $this->assertSame(1, DB::table('admin_audit_logs')->where('action', 'update.texts')->count());
    }

    /**
     * A Finish that finds the update lock taken (another Finish, a step of an update) leaves the texts and says so;
     * Tools > Updates then offers them, and they are corrected once the lock is free.
     */
    public function test_a_finish_that_finds_the_update_lock_taken_says_so_and_the_texts_wait_on_tools_updates(): void
    {
        $this->handSite();
        $admin = User::factory()->admin()->create(['username' => 'owner']);
        config(['admin.ui' => 'v2']);
        $before = $this->box->read('lang/de.json');
        File::ensureDirectoryExists($this->box->paths()->work());
        $lock = fopen($this->box->paths()->lock(), 'c');
        $this->assertTrue(flock($lock, LOCK_EX));

        try {
            $this->actingAs($admin)->post(route('admin.update.finish'))->assertRedirect(route('admin.index'))->assertSessionHas('success',
                'Your site is up to date. Your language files were not checked this time, because another update step was running. Try again in a minute on Tools > Updates.');
            $this->actingAs($admin)->post(route('admin.update.finish'), ['only' => 'texts'])->assertRedirect(route('admin.updates.index'))
                ->assertSessionHasErrors(['update' => 'Your language files were not checked this time, because another update step was running. Try again in a minute on Tools > Updates.']);
            $this->actingAs($admin)->postJson(route('admin.update.finish'), ['only' => 'texts'])->assertStatus(409)->assertJson(['ok' => false, 'texts_state' => 'busy']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertSame($before, $this->box->read('lang/de.json'));
        $this->assertSame(0, DB::table('admin_audit_logs')->where('action', 'update.texts')->count());
        $this->assertStringContainsString('2 texts to correct', $this->actingAs($admin)->get(route('admin.updates.index'))->getContent());

        $this->actingAs($admin)->post(route('admin.update.finish'), ['only' => 'texts'])->assertSessionHas('success',
            'Texts you never changed in your language files now have the corrected wording of this version (Deutsch: 2 texts corrected, your own wording kept for 1 text this version corrects).');
        $this->assertSame('Löschen', $this->language('de')['Delete']);
    }

    /**
     * A language file the site cannot save (a read-only file or folder): it stays exactly as it was, no temporary file is
     * left behind, the answer says so (never "no texts left"), the admin log has its row, and Tools > Updates still lists
     * it. Once it can be saved, one click corrects it.
     */
    public function test_a_language_file_that_cannot_be_saved_stays_as_it_was_and_the_answer_says_so(): void
    {
        $this->handSite();
        $admin = User::factory()->admin()->create(['username' => 'owner']);
        config(['admin.ui' => 'v2']);
        $german = $this->box->root.'/lang/de.json';
        $before = (string) file_get_contents($german);
        // Windows refuses to replace a read-only file; elsewhere a folder without write access refuses the new file.
        $locked = PHP_OS_FAMILY === 'Windows' ? $german : dirname($german);

        if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write anywhere');
        }

        chmod($locked, PHP_OS_FAMILY === 'Windows' ? 0444 : 0555);

        try {
            $this->actingAs($admin)->post(route('admin.update.finish'), ['only' => 'texts'])->assertRedirect(route('admin.updates.index'))
                ->assertSessionHasErrors(['update' => 'These language files could not be saved, so their texts stay as they were: lang/de.json.']);
            clearstatcache();
            $this->assertSame($before, (string) file_get_contents($german));
            $this->assertSame(['de.json', 'fr.json'], array_values(array_diff(scandir(dirname($german)) ?: [], ['.', '..'])), 'no temporary file left behind');
            $this->assertSame(['lang/de.json (Deutsch): not corrected (it could not be saved), your own wording kept for 1 text this version corrects'],
                DB::table('admin_audit_logs')->where('action', 'update.texts')->pluck('details')->all());
        } finally {
            chmod($locked, PHP_OS_FAMILY === 'Windows' ? 0644 : 0755);
        }

        $this->assertStringContainsString('2 texts to correct', $this->actingAs($admin)->get(route('admin.updates.index'))->getContent());
        $this->actingAs($admin)->post(route('admin.update.finish'), ['only' => 'texts'])->assertSessionHas('success',
            'Texts you never changed in your language files now have the corrected wording of this version (Deutsch: 2 texts corrected, your own wording kept for 1 text this version corrects).');
    }

    /** The way by hand over SSH: php artisan updates:texts does what Finish update does with the texts. */
    public function test_php_artisan_updates_texts_corrects_the_texts_on_the_command_line(): void
    {
        $this->handSite();

        $this->artisan('updates:texts')
            ->expectsOutputToContain('lang/de.json (Deutsch): 2 texts corrected, your own wording kept for 1 text this version corrects')
            ->expectsOutputToContain('Done: 2 texts now have the corrected wording of this version.')
            ->assertExitCode(0);
        $this->assertSame(['Delete' => 'Löschen', 'Home' => 'Start', 'Mein Schlüssel' => 'Mein Text', 'Save' => 'Speichern', 'Upload' => 'Datei senden'], $this->language('de'));
        $this->assertSame([['lang/de.json (Deutsch): 2 texts corrected to the wording of this version, your own wording kept for 1 text this version corrects', null]],
            DB::table('admin_audit_logs')->where('action', 'update.texts')->get(['details', 'user_id'])->map(fn (object $row): array => [$row->details, $row->user_id])->all());

        // Again: nothing left to correct, nothing written.
        $tree = $this->box->snapshot();
        $this->artisan('updates:texts')->expectsOutputToContain('Done: no text needed a correction.')->assertExitCode(0);
        $this->assertSame($tree, $this->box->snapshot());

        // The update lock taken, or a one-click update that runs: nothing read, exit 1.
        $lock = fopen($this->box->paths()->lock(), 'c');
        $this->assertTrue(flock($lock, LOCK_EX));

        try {
            $this->artisan('updates:texts')->expectsOutputToContain('another update step is running')->assertExitCode(1);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        PlanStore::write($this->box->paths()->plan(), ['status' => 'downloading', 'kind' => 'update', 'version' => '9.9.9', 'admin_id' => 1, 'step_at' => time(), 'started_at' => time()]);
        $this->artisan('updates:texts')->expectsOutputToContain('a one-click update or undo is running')->assertExitCode(1);
        File::delete($this->box->paths()->plan());

        // Not an installed site (no manifest of this version): nothing to do.
        File::delete($this->box->paths()->manifest());
        $this->artisan('updates:texts')->expectsOutputToContain('Nothing to correct')->assertExitCode(0);
    }

    /**
     * What Tools > Updates offers is found once and kept in one cache entry with the state of the files; a change of a
     * language file finds it anew. Nothing while a one-click update runs, nothing on a site without a manifest.
     */
    public function test_the_texts_to_correct_are_found_once_and_again_after_a_language_file_changes(): void
    {
        $this->handSite();
        $updater = app(Updater::class);

        $this->assertSame(['de' => 2], $updater->pendingTexts());
        $kept = Cache::get('avdbcms.updates.pending-texts');
        $this->assertSame(['de' => 2], $kept['texts']);
        $this->assertSame(['de' => 2], $updater->pendingTexts(), 'from the cache');

        // The kept answer is used while the files stay as they are (here: an answer planted for this state).
        Cache::put('avdbcms.updates.pending-texts', ['stamp' => $kept['stamp'], 'texts' => ['de' => 7]], 60);
        $this->assertSame(['de' => 7], $updater->pendingTexts());

        // The owner corrects one text by hand on Settings > Languages: the file changed, so it is looked at again.
        $this->box->write(['lang/de.json' => TranslationMerge::encode(['Delete' => 'Löschen'] + self::DE_OWNER)]);
        touch($this->box->root.'/lang/de.json', time() + 5);
        $this->assertSame(['de' => 1], $updater->pendingTexts());

        // A one-click update runs: nothing (its own Finish does them).
        PlanStore::write($this->box->paths()->plan(), ['status' => 'downloading', 'kind' => 'update', 'version' => '9.9.9', 'admin_id' => 1, 'step_at' => time(), 'started_at' => time()]);
        $this->assertSame([], $updater->pendingTexts());
        File::delete($this->box->paths()->plan());

        // Not an installed site: nothing.
        File::delete($this->box->paths()->manifest());
        $this->assertSame([], $updater->pendingTexts());
    }

    /* ----------------------------------------------------------------- helpers */

    /** The one-click scenario: the installed site with the owner's edits, the release, GitHub. */
    private function site(): void
    {
        $this->makeSite();
        $this->box->write(['lang/de.json' => TranslationMerge::encode(self::DE_OWNER), 'lang/es.json' => TranslationMerge::encode(self::ES_OWNER)]);
        $this->before = $this->box->snapshot();
    }

    /**
     * A site updated by hand to this version: the release's language files and data file (made for this version) with
     * their manifest, the owner's German imported again (the file saved before the upload).
     */
    private function handSite(): void
    {
        $this->box = new UpdaterSandbox();
        $files = [
            'index.php' => "<?php // index.php\n",
            'lang/de.json' => TranslationMerge::encode(self::DE_RELEASE),
            'lang/fr.json' => TranslationMerge::encode(self::FR_RELEASE),
            ShippedTexts::PATH => ShippedTexts::encodeData(ShippedTexts::build(['de' => self::DE_RELEASE, 'fr' => self::FR_RELEASE], [['de' => self::DE_SHIPPED, 'fr' => self::FR_SHIPPED]], ['1.2.0'], AppVersion::CURRENT)),
        ];
        $this->box->install(AppVersion::CURRENT, $files);
        $this->box->write(['lang/de.json' => TranslationMerge::encode(self::DE_OWNER)]);
        $this->database = new FakeDatabaseBackup($this->box->root.'/storage/app/backups');
        $this->app->instance(Updater::class, new Updater($this->box->paths(), new ReleaseCheck(), $this->database, new Downloader(), AppVersion::CURRENT));
    }

    /** The updater whose steps run up to the swap: this version's code, or the code an older release shipped. */
    private function oldUpdater(string $code): object
    {
        return match ($code) {
            '1.2.0' => Updater120::updater($this->box->paths(), $this->database, AppVersion::CURRENT),
            '1.2.1' => Updater121::updater($this->box->paths(), $this->database, AppVersion::CURRENT),
            '1.2.2' => Updater122::updater($this->box->paths(), $this->database, AppVersion::CURRENT),
            default => $this->updater(),
        };
    }

    /** Start + steps until the swap is ready, on any version's updater; the swap token. */
    private function toSwap(object $updater): string
    {
        $start = $updater->start($this->admin);
        $this->assertTrue($start['ok'], (string) $start['message']);

        for ($i = 0; $i < 40; $i++) {
            $answer = $updater->step($this->admin);
            $this->assertTrue($answer['ok'], (string) $answer['message']);

            if ($answer['next'] !== 'step') {
                $this->assertSame('swap', $answer['next']);

                return (string) $answer['token'];
            }
        }

        $this->fail('The update did not get to the swap in 40 steps.');
    }

    /** @return array<string, string>|null */
    private function language(string $locale): ?array
    {
        return TranslationMerge::decode((string) $this->box->read('lang/'.$locale.'.json'));
    }

    /**
     * Keys in byte order, as a language file holds them (TranslationMerge::encode()).
     *
     * @param  array<string, string>  $texts
     * @return array<string, string>
     */
    private static function sorted(array $texts): array
    {
        ksort($texts, SORT_STRING);

        return $texts;
    }

    /** @param  array<string, string>  $tree */
    private function withoutCompiledCaches(array $tree): array
    {
        unset($tree['bootstrap/cache/config.php'], $tree['bootstrap/cache/packages.php']);

        return $tree;
    }
}
