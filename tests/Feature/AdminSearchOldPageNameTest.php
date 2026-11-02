<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsPageController;
use App\Models\User;
use App\Support\AdminSearchIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R1-124: the old settings page of 1.2.0-1.2.2 called the switch now labelled 'Creators can show "Last upload"'
 * "Upload Rhythm", machine-translated as "upload a rhythm" in every other language ("Rhythmus hochladen", "Загрузить
 * ритм"); the admin search showed those words as the setting's old name. 1.2.3 shows the corrected name (lang/*.json)
 * and keeps the name each admin language showed before as a search word only (scripts/admin_settings/generate_config.py
 * FORMER_OLD_NAMES): in every language of the admin both names, the English one and the label of the new admin of 1.2.x
 * (R1R-18) find the setting first, in the search of Settings and in Ctrl+K's index.
 */
class AdminSearchOldPageNameTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'channel_customization_upload_rhythm_enabled';

    /** The old page's name as 1.2.0-1.2.2 showed it (lang/<locale>.json of the three packages, all the same). */
    private const SHOWN_BY_1_2_X = [
        'ar' => 'تحميل الإيقاع',
        'de' => 'Rhythmus hochladen',
        'es' => 'Subir ritmo',
        'fil' => 'Mag-upload ng Rhythm',
        'fr' => 'Rythme de téléchargement',
        'hi' => 'लय अपलोड करें',
        'id' => 'Unggah Irama',
        'ja' => 'アップロードリズム',
        'ko' => '리듬 업로드',
        'ms' => 'Muat naik Irama',
        'pt' => 'Carregar ritmo',
        'ru' => 'Загрузить ритм',
        'th' => 'อัพโหลด ริธึม',
        'tl' => 'Mag-upload ng Rhythm',
        'tr' => 'Ritim Yükle',
        'vi' => 'Tải lên nhịp điệu',
        'zh' => '上传节奏',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2']);
    }

    public function test_in_every_language_the_old_page_name_finds_the_setting_first_as_shown_now_and_as_shown_before(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertSame('Upload Rhythm', config('admin_settings.settings.'.self::KEY.'.old.1'), 'the setting keeps its old name');

        foreach (self::SHOWN_BY_1_2_X as $locale => $before) {
            app()->setLocale($locale);
            $now = __('Upload Rhythm');
            $this->assertNotSame($before, $now, "{$locale}: the old name is shown in its corrected words");

            foreach (['shown now' => $now, 'shown by 1.2.x' => $before, 'in English' => 'Upload Rhythm', 'as typed' => mb_strtolower($before),
                'label of the new admin of 1.2.x' => __('Creators can change the upload schedule')] as $what => $query) {
                $found = array_column(SettingsPageController::searchSettings($query, $admin), 'key');
                $this->assertSame(self::KEY, $found[0] ?? null, "{$locale}, {$what} \"{$query}\": ".implode(', ', array_slice($found, 0, 5)));
            }

            // Ctrl+K: the row shows the corrected name as the old name; the name of 1.2.x is a search word only
            $index = AdminSearchIndex::build($admin);
            $row = collect($index['settings'])->firstWhere('k', self::KEY);
            $this->assertNotNull($row, "{$locale}: Ctrl+K lists the setting");
            $this->assertContains($now, $row['o'] ?? [], "{$locale}: Ctrl+K shows the old name in its corrected words");
            $this->assertNotContains($before, $row['o'] ?? [], "{$locale}: Ctrl+K never shows the words of 1.2.x");
            $this->assertSame([self::KEY], $index['synonyms'][mb_strtolower($before)] ?? null, "{$locale}: Ctrl+K finds it by the words of 1.2.x");
        }
    }
}
