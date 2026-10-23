<?php

namespace Tests\Feature;

use Avd\I18n\Extractor;
use Avd\I18n\Inventory;
use Avd\I18n\JsLangFile;
use Avd\I18n\LangFile;
use Avd\I18n\Paths;
use Avd\I18n\Placeholders;
use Avd\I18n\Plurals;
use Avd\I18n\Requirements;
use Avd\I18n\Status;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Facades\File;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Tests\TestCase;

/**
 * The redesign's admin and theme wording (scripts/i18n/inventory.json) ships in all 18 locales, Vietnamese
 * reviewed by hand, the other 16 machine-translated; scripts/i18n_check.php guards it for the release.
 */
class I18nAdminCoverageTest extends TestCase
{
    private const LOCALES = ['ar', 'de', 'en', 'es', 'fil', 'fr', 'hi', 'id', 'ja', 'ko', 'ms', 'pt', 'ru', 'th', 'tl', 'tr', 'vi', 'zh'];

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('scripts/i18n/I18n.php');
    }

    public function test_every_locale_file_is_a_flat_json_object_of_strings(): void
    {
        $this->assertSame(self::LOCALES, Paths::locales(base_path()));

        foreach (self::LOCALES as $locale) {
            [$data] = LangFile::read(lang_path($locale . '.json'));
            $this->assertGreaterThan(7000, count($data), "lang/{$locale}.json lost keys");
            $this->assertSame([], array_filter($data, static fn ($value) => !is_string($value) || $value === ''), "lang/{$locale}.json has empty values");
        }
    }

    public function test_the_inventory_holds_the_known_admin_and_theme_strings(): void
    {
        $inventory = Inventory::load(base_path());

        $this->assertGreaterThanOrEqual(1733, count(array_filter($inventory, static fn (array $row) => $row['source'] === 'admin_spec' || $row['source'] === 'theme_spec')));
        foreach (['Site name', 'Effects & speed', 'Complete looks', 'Midnight Spotlight', 'Hot Pink', 'Neon Nights', 'YouTube Spotlight Pro', 'Tonight in the lounge', 'Halloween'] as $text) {
            $this->assertArrayHasKey($text, $inventory);
        }
        $this->assertSame('Site Name', $inventory['Site name']['old_label']);
    }

    public function test_every_inventory_string_is_in_every_locale_with_its_placeholders(): void
    {
        $required = Requirements::collect(base_path(), false);

        foreach (self::LOCALES as $locale) {
            [$data] = LangFile::read(lang_path($locale . '.json'));
            $missing = array_diff_key($required, $data);
            $this->assertSame([], array_keys($missing), "missing in lang/{$locale}.json");

            $broken = [];
            foreach ($required as $key => $meta) {
                if (!Placeholders::same($meta['source'], $data[$key])) {
                    $broken[] = $key;
                }
            }
            $this->assertSame([], $broken, "placeholders changed in lang/{$locale}.json");
        }

        [$en] = LangFile::read(lang_path('en.json'));
        foreach (Inventory::load(base_path()) as $key => $row) {
            $this->assertSame($key, $en[$key]);
        }
    }

    public function test_vietnamese_has_no_missing_untranslated_or_corrupted_required_string(): void
    {
        // Every key the lang files know. Strings that code adds later fail scripts/i18n_check.php (the release gate)
        // until the translation pass has added them; this test keeps checking what was translated.
        [$en] = LangFile::read(lang_path('en.json'));
        [$vi] = LangFile::read(lang_path('vi.json'));
        $keep = Inventory::accepted(base_path(), 'vi');
        $bad = [];
        foreach (array_intersect_key(Requirements::collect(base_path()), $en) as $key => $meta) {
            $status = Status::of($key, $meta['old_label'], $vi, 'vi', $keep, $meta['source']);
            if (in_array($status, [Status::MISSING, Status::ENGLISH, Status::CORRUPT], true)) {
                $bad[$key] = $status;
            }
        }

        $this->assertSame([], $bad);
    }

    /**
     * Wrong senses a human review found in the new admin (I18N review, phase D): idioms machine translation took word
     * for word. 'goes' (= is deleted) became 'is kept/saved' or 'goes/walks'; 'for good' (= permanently) became 'for the
     * good / for kindness'; 'moved for good/for now' (HTTP 301/302) became 'moved house'; 'the old admin' (the old panel)
     * became a person; a free 'trial' became a court trial. Each value must keep none of these fragments.
     */
    public function test_reviewed_wrong_senses_do_not_come_back(): void
    {
        $payment = 'Only the record on your site goes; the payment itself stays with the payment provider. You can undo for a few seconds after this.';
        $views = 'The view counts of your videos stay. Only the list of who watched what goes. This cannot be undone.';
        $wrong = [
            $payment => ['zh' => ['保存'], 'ja' => ['保存'], 'ko' => ['기록만 남습니다'], 'fr' => ['conservé'], 'es' => ['va el registro'],
                'pt' => ['site vai'], 'ar' => ['يذهب'], 'hi' => ['रिकॉर्ड जाता है'], 'ru' => ['Идет'], 'th' => ['จะไป'], 'tl' => ['napupunta'],
                'fil' => ['napupunta'], 'tr' => ['kayıt gider'], 'id' => ['yang masuk'], 'ms' => ['yang hilang']],
            $views => ['fr' => ['ce qui se passe'], 'ko' => ['표시됩니다'], 'ar' => ['ما يجري'], 'es' => ['lo que va'], 'ru' => ['что идет'],
                'id' => ['terjadi'], 'ms' => ['berlaku'], 'tl' => ['nangyayari'], 'fil' => ['nangyayari'], 'th' => ['ที่ไป'], 'pt' => ['o que vai']],
            'Every change you have not published goes back to what your visitors see now.' => ['es' => ['remonta'], 'pt' => ['remonta'], 'fr' => ['renvoie']],
            'For good' => ['ar' => ['الخير'], 'es' => ['Para bien'], 'hi' => ['अच्छे के लिए'], 'ms' => ['kebaikan'], 'th' => ['ความดี'],
                'tl' => ['kabutihan'], 'fil' => ['kabutihan'], 'tr' => ['İyilik']],
            'Delete this video for good?' => ['th' => ['ดีไหม'], 'ms' => ['kebaikan'], 'tl' => ['kabutihan'], 'fil' => ['kabutihan']],
            'Delete this member for good?' => ['th' => ['ดีไหม'], 'tl' => ['kabutihan'], 'fil' => ['kabutihan'], 'es' => ['esta miembro']],
            'Moved for good (301)' => ['zh' => ['搬家'], 'ja' => ['引っ越'], 'ko' => ['이사'], 'fr' => ['Déménagé'], 'hi' => ['अच्छे के लिए'],
                'ms' => ['kebaikan'], 'tl' => ['kabutihan'], 'fil' => ['kabutihan']],
            'Moved for now (302)' => ['zh' => ['搬家'], 'ko' => ['이사']],
            'Use the old admin for now' => ['zh' => ['管理员'], 'ru' => ['администрацию'], 'ja' => ['管理者'], 'es' => ['administrador anterior'],
                'pt' => ['administrador antigo'], 'fr' => ["l'ancien administrateur"], 'th' => ['คนเดิม'], 'tr' => ['yöneticiyi'], 'ar' => ['المشرف']],
            'Admin' => ['zh' => ['行政'], 'es' => ['Administradora'], 'fr' => ['Administratrice'], 'ar' => ['مسؤل']],
            'Who is signed in where. Sign people out. Pending password resets.' => ['zh' => ['签名'], 'ru' => ['подписан'], 'hi' => ['हस्ताक्षरित'], 'es' => ['Firmar']],
            'Trial Ends' => ['zh' => ['审判'], 'es' => ['juicio'], 'ar' => ['المحاكمة']],
        ];
        $found = [];
        $cache = [];
        foreach ($wrong as $key => $byLocale) {
            foreach ($byLocale as $locale => $fragments) {
                $cache[$locale] ??= LangFile::read(lang_path($locale . '.json'))[0];
                $value = $cache[$locale][$key] ?? null;
                $this->assertIsString($value, "{$locale}: {$key}");
                foreach ($fragments as $fragment) {
                    if (mb_strpos($value, $fragment) !== false) {
                        $found[] = "{$locale}: \"{$key}\" = \"{$value}\" (has \"{$fragment}\")";
                    }
                }
            }
        }

        $this->assertSame([], $found);
    }

    public function test_laravel_serves_the_new_strings_in_vietnamese_and_arabic(): void
    {
        $this->app->setLocale('vi');
        $this->assertSame('Tên trang web', __('Site name'));
        $this->assertSame('Hiệu ứng & tốc độ', __('Effects & speed'));
        $this->assertSame('Hồng rực', __('Hot Pink'));
        $this->assertSame('Midnight Spotlight', __('Midnight Spotlight'));
        $this->assertSame('Phát', __('Play'));
        $this->assertSame('{site_name} được thay bằng tên trang web của bạn.', __('{site_name} is replaced by your site name.'));
        $this->assertSame('Khoản thanh toán 9 USD gần nhất của bạn cho AVD không thành công',
            __('Your last payment of :amount to :app was unsuccessful', ['amount' => '9 USD', 'app' => 'AVD']));

        $this->assertSame('Tinh tế', __('Subtle'));
        $this->assertSame('ví dụ: vi, fr, ja', __('e.g. vi, fr, ja'));

        $this->app->setLocale('ar');
        $this->assertNotSame('Effects & speed', __('Effects & speed'));
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', __('Complete looks'));

        // camelCase replacements reach the reader (they were lost to machine translation once)
        $this->app->setLocale('de');
        $this->assertSame('Kommentar von Anna und 3 zugehörige Antworten löschen',
            __('Delete :author comment and :repliesCount associated replies', ['author' => 'Anna', 'repliesCount' => 3]));
        $this->app->setLocale('fr');
        $line = __('Your file is too large (:fileSize) The uploading file should not exceed :maxSize', ['fileSize' => '300 MB', 'maxSize' => '256 MB']);
        $this->assertStringContainsString('(300 MB)', $line);
        $this->assertStringContainsString('256 MB', $line);
        $this->assertStringNotContainsString(':', str_replace(['(300 MB)', '256 MB'], '', $line));
    }

    public function test_the_translator_reads_each_locale_file_once_per_request(): void
    {
        $spy = new class(new FileLoader(new \Illuminate\Filesystem\Filesystem(), [lang_path()])) implements Loader {
            public int $jsonLoads = 0;

            public function __construct(private FileLoader $inner)
            {
            }

            public function load($locale, $group, $namespace = null)
            {
                if ($group === '*' && $namespace === '*') {
                    $this->jsonLoads++;
                }

                return $this->inner->load($locale, $group, $namespace);
            }

            public function addNamespace($namespace, $hint)
            {
                $this->inner->addNamespace($namespace, $hint);
            }

            public function addJsonPath($path)
            {
                $this->inner->addJsonPath($path);
            }

            public function namespaces()
            {
                return $this->inner->namespaces();
            }
        };
        $translator = new Translator($spy, 'vi');
        $translator->setFallback('en');

        foreach (array_slice(array_keys(Inventory::load(base_path())), 0, 300) as $key) {
            $translator->get($key);
        }

        $this->assertSame(1, $spy->jsonLoads, 'lang/vi.json must be decoded once and then served from the translator cache');
        foreach (self::LOCALES as $locale) {
            $this->assertLessThan(1_600_000, filesize(lang_path($locale . '.json')), "lang/{$locale}.json grew past the size budget");
        }
    }

    public function test_release_check_passes_with_full_coverage(): void
    {
        // the inventory part of the gate (the source-code part follows the code: see the test above)
        [$code, $out] = $this->runScript('scripts/i18n_check.php', ['--json', '--min-coverage=95', '--no-sources', '--no-js']);
        $report = json_decode(substr($out, (int) strpos($out, '{')), true);

        $this->assertSame(0, $code, $out);
        $this->assertTrue($report['ok']);
        $this->assertSame(0, $report['locales']['vi']['missing']);
        $this->assertSame(0, $report['locales']['vi']['english']);
        foreach ($report['locales'] as $locale => $row) {
            $this->assertGreaterThanOrEqual(95, $row['coverage'], "coverage of {$locale}");
            $this->assertGreaterThanOrEqual(95, $row['translated_coverage'], "translated coverage of {$locale} (accepted English excluded)");
            $this->assertSame(0, $row['placeholder_errors'], "placeholders of {$locale}");
            $this->assertSame(0, $row['corrupt'], "corrupted text in {$locale}");
        }
    }

    public function test_release_check_compares_camel_case_placeholders_exactly(): void
    {
        $key = 'Delete :author comment and :repliesCount associated replies';
        $root = $this->fixture([
            'en' => [$key => $key],
            'vi' => [$key => 'Xóa bình luận của :author và :repliesSố phản hồi liên quan'],
            'de' => [$key => 'Kommentar von :author und :repliesCount zugehörige Antworten löschen'],
        ], [$key]);

        [$code, $out] = $this->runScript('scripts/i18n_check.php', ['--root=' . $root]);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('vi: placeholders differ', $out);
        $this->assertStringContainsString('[":repliesCount"]', $out);

        // Laravel also replaces the ucfirst and UPPER forms (:Author, :REPLIESCOUNT); a German value that lost the
        // tail of the name to the machine translator (":repliesZählen") fails in any locale
        File::put($root . '/lang/vi.json', json_encode([$key => ':Author: xóa bình luận và :REPLIESCOUNT phản hồi liên quan'], JSON_UNESCAPED_UNICODE));
        File::put($root . '/lang/de.json', json_encode([$key => 'Löschen Sie :author Kommentar und :repliesZählen Sie die zugehörigen Antworten'], JSON_UNESCAPED_UNICODE));
        [$code, $out] = $this->runScript('scripts/i18n_check.php', ['--root=' . $root]);
        $this->assertSame(1, $code, $out);
        $this->assertStringNotContainsString('vi: placeholders differ', $out);
        $this->assertStringContainsString('de: placeholders differ', $out);

        $this->assertTrue(Placeholders::same('Your file is too large (:fileSize)', 'Votre fichier est trop volumineux (:fileSize)'));
        $this->assertFalse(Placeholders::same('Your file is too large (:fileSize)', 'Votre fichier est trop volumineux (:fileTaille)'));
        $this->assertSame([':BOKD'], Placeholders::diff('Use the code, for example BOKD-197.', 'コードを使用します (例:BOKD-197)。')['extra']);
        $this->assertTrue(Placeholders::same('Use the code, for example BOKD-197.', 'コードを使用します (例：BOKD-197)。'));
    }

    public function test_corrupted_text_is_found_but_real_questions_are_not(): void
    {
        foreach ([
            ['Add', 'Th?m'], ['e.g. Watch Later', 'v? d?: Xem sau'], ['Subtle', 'Tinh t?'], ['Subtle', '?nce'],
            ['e.g. vi, fr, ja', '?: vi, fr, ja'], ['lines', '?'], ['e.g. Watch Later', 'par ex. ? regarder plus tard'],
            ['Arabic', '??????'], ['2–5 letters', '2?5 letters'], ['Anime', 'Anime - Anime (no translation needed)'],
        ] as [$source, $value]) {
            $this->assertTrue(Status::corrupt($value, $source), "\"{$value}\" is corrupted");
        }
        foreach ([
            ['Who pays for premium. Cancel, activate or extend.', 'Wer zahlt die Prämie? Abbrechen, aktivieren oder verlängern.'],
            ['Who can still see the site', '¿Quién todavía puede ver el sitio?'],
            ['What Google may list', 'Google neler listeleyebilir?'],
            ['Forgot password', '비밀번호를 잊으셨나요?'],
            ['Delete ALL notifications?', 'Xóa TẤT CẢ thông báo?'],
        ] as [$source, $value]) {
            $this->assertFalse(Status::corrupt($value, $source), "\"{$value}\" is a question, not corruption");
        }

        $root = $this->fixture([
            'en' => ['e.g. Watch Later' => 'e.g. Watch Later'],
            'vi' => ['e.g. Watch Later' => 'v? d?: Xem sau'],
        ], ['e.g. Watch Later']);
        [$code, $out] = $this->runScript('scripts/i18n_check.php', ['--root=' . $root]);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('vi: corrupted text "e.g. Watch Later"', $out);
    }

    public function test_english_a_locale_merely_accepted_does_not_count_as_translated(): void
    {
        $strings = ['Media Library', 'Time zone', 'Spam filter', 'Username', 'Save'];
        $root = $this->fixture([
            'en' => array_combine($strings, $strings),
            'vi' => ['Media Library' => 'Thư viện media', 'Time zone' => 'Múi giờ', 'Spam filter' => 'Bộ lọc spam', 'Username' => 'Tên người dùng', 'Save' => 'Lưu'],
            'fil' => ['Media Library' => 'Media Library', 'Time zone' => 'Time zone', 'Spam filter' => 'Spam filter', 'Username' => 'Username', 'Save' => 'I-save'],
        ], $strings);
        File::put($root . '/scripts/i18n/accepted.json', json_encode(['locales' => ['fil' => ['Media Library', 'Time zone', 'Spam filter', 'Username']]]));

        [$code, $out] = $this->runScript('scripts/i18n_check.php', ['--root=' . $root, '--min-coverage=95', '--json']);
        $report = json_decode(substr($out, (int) strpos($out, '{')), true);
        $this->assertSame(1, $code, $out);
        $this->assertSame(100.0, (float) $report['locales']['fil']['coverage']);
        $this->assertSame(20.0, (float) $report['locales']['fil']['translated_coverage']);
        $this->assertSame(4, $report['locales']['fil']['accepted_english']);

        // the machine translator returned two English strings: only the single word is accepted automatically
        File::put($root . '/scripts/i18n/accepted.json', json_encode(['locales' => []]));
        File::ensureDirectoryExists($root . '/mt');
        File::put($root . '/mt/fil.mt.json', json_encode(['Media Library' => 'Media Library', 'Username' => 'Username']));
        [$code, $out] = $this->runScript('scripts/i18n/sync.php', ['--root=' . $root, '--from=' . $root . '/mt', '--accept-english']);
        $this->assertSame(0, $code, $out);
        $this->assertSame(['Username'], json_decode(File::get($root . '/scripts/i18n/accepted.json'), true)['locales']['fil']);
    }

    public function test_technical_text_and_brands_survive_translation(): void
    {
        $protected = [
            'Mailer is "log": the test email for :to was written to storage/logs/mail.log, not delivered. Configure SMTP in Settings → Email to send real mail.' => ['storage/logs/mail.log'],
            'e.g. vi, fr, ja' => ['vi, fr, ja'],
            '2–5 lowercase letters (ISO 639-1). Examples: en, vi, zh' => ['en', 'vi', 'zh'],
            'Country flag emoji. Examples: 🇻🇳 🇫🇷 🇨🇳 🇯🇵' => ['🇻🇳', '🇫🇷', '🇨🇳', '🇯🇵'],
            'X-XSS-Protection Header' => ['X-XSS-Protection'],
            'X-Frame-Options: SAMEORIGIN' => ['X-Frame-Options', 'SAMEORIGIN'],
            'The word in every video address, like /video/my-title. Changing it changes every video link.' => ['/video/'],
        ];
        foreach (self::LOCALES as $locale) {
            [$data] = LangFile::read(lang_path($locale . '.json'));
            foreach ($protected as $key => $tokens) {
                foreach ($tokens as $token) {
                    $this->assertStringContainsString($token, $data[$key], "{$locale}: \"{$key}\" lost {$token}");
                }
            }
        }
        foreach (JsLangFile::locales(base_path()) as $locale) {
            [$data] = JsLangFile::read(JsLangFile::path(base_path(), $locale));
            foreach (array_filter(array_keys($data), static fn ($key) => str_contains($key, 'Upload18')) as $key) {
                $this->assertStringContainsString('Upload18', $data[$key], "{$locale}.ts: \"{$key}\" translated the brand");
            }
            $this->assertNotSame('', trim($data['Skip in']));
        }
    }

    public function test_extractor_reads_labels_of_catalog_classes(): void
    {
        $fonts = Extractor::fromCatalog(base_path(), 'App\\Support\\AppearanceSettings::fontFamilies', ['label']);
        $this->assertContains('Interface Sans', $fonts);
        $this->assertNotContains('inter', $fonts);

        $map = Extractor::fromCatalog(base_path(), 'App\\Support\\AppearanceSettings::scrollbarStyles', ['*']);
        $this->assertContains('Subtle Glass', $map);

        $this->assertSame([], Extractor::fromCatalog(base_path(), 'App\\Support\\AppearanceSettings::noSuchMethod', ['label']));
        $this->assertArrayHasKey('App\\Support\\AppearanceSettings::noSuchMethod', Extractor::$skipped);

        $required = Requirements::collect(base_path());
        $this->assertSame('App\\Support\\AppearanceSettings::fontFamilies', $required['Interface Sans']['origin']);
    }

    public function test_release_check_fails_when_a_vietnamese_string_is_missing_or_breaks_a_placeholder(): void
    {
        $root = $this->fixture([
            'en' => ['Save' => 'Save', 'Hello :name' => 'Hello :name'],
            'vi' => ['Save' => 'Lưu'],
        ], ['Save', 'Hello :name']);

        [$code, $out] = $this->runScript('scripts/i18n_check.php', ['--root=' . $root]);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('vi: missing "Hello :name"', $out);

        File::put($root . '/lang/vi.json', json_encode(['Save' => 'Lưu', 'Hello :name' => 'Xin chào'], JSON_UNESCAPED_UNICODE));
        [$code, $out] = $this->runScript('scripts/i18n_check.php', ['--root=' . $root]);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('placeholders differ in "Hello :name"', $out);

        File::put($root . '/lang/vi.json', json_encode(['Save' => 'Lưu', 'Hello :name' => 'Xin chào :name'], JSON_UNESCAPED_UNICODE));
        [$code, $out] = $this->runScript('scripts/i18n_check.php', ['--root=' . $root]);
        $this->assertSame(0, $code, $out);
    }

    /**
     * The quality checks the review asked for: a protected brand translated as a common word ("Stripe" -> "stripes"),
     * an English sentence of the source left in a value, zero-width characters, and a trans_choice form for 1 that
     * lost its number ("1 found nothing." -> "I found nothing."). Each one fails the release check; the fixed value passes.
     */
    public function test_release_check_fails_on_lost_brands_english_sentences_invisible_characters_and_lost_ones(): void
    {
        $keys = ['Stripe checkout', 'Every member is removed. This cannot be undone.', 'Price (cents)', '1 found nothing.|:count found nothing.'];
        $good = ['vi' => ['Thanh toán Stripe', 'Mọi thành viên bị xóa. Không thể hoàn tác.', 'Giá (xu)', '{1} 1 lượt tìm không có kết quả.|[0,*] :count lượt tìm không có kết quả.'],
            'zh' => ['Stripe 结账', '所有会员都将被删除。此操作无法撤消。', '价格（分）', '{1} 1 次搜索没有结果。|[0,*] :count 次搜索没有结果。']];
        $bad = [
            'brand' => ['zh', 0, '条纹结账', 'zh: brand "Stripe checkout"'],
            'english sentence' => ['zh', 1, 'Every member is removed. 此操作无法撤消。', 'zh: English sentence in "Every member is removed.'],
            'zero-width' => ['vi', 2, "Giá\u{200B}\u{200B}(xu)", 'vi: invisible characters in "Price (cents)"'],
            'form for 1' => ['vi', 3, '{1} Tôi không tìm thấy gì.|[0,*] :count lượt tìm không có kết quả.', 'vi: plural form for 1 of "1 found nothing.'],
        ];
        $locales = ['en' => array_combine($keys, $keys)];
        foreach ($good as $locale => $values) {
            $locales[$locale] = array_combine($keys, $values);
        }
        $root = $this->fixture($locales, $keys);
        File::put($root . '/scripts/i18n/brands.json', json_encode(['brands' => ['Stripe' => ['ja' => ['ストライプ']]], 'keys' => (object) [], 'not_brands' => []]));
        File::put($root . '/scripts/i18n/number_words.json', json_encode(['locales' => ['vi' => ['một'], 'zh' => ['一']]], JSON_UNESCAPED_UNICODE));

        [$code, $out] = $this->runScript('scripts/i18n_check.php', ['--root=' . $root, '--no-js']);
        $this->assertSame(0, $code, 'the right values pass: ' . $out);

        foreach ($bad as $what => [$locale, $index, $value, $message]) {
            $data = $locales[$locale];
            $data[$keys[$index]] = $value;
            File::put($root . '/lang/' . $locale . '.json', json_encode($data, JSON_UNESCAPED_UNICODE));
            [$code, $out] = $this->runScript('scripts/i18n_check.php', ['--root=' . $root, '--no-js']);
            $this->assertSame(1, $code, "{$what}: {$out}");
            $this->assertStringContainsString($message, $out, $what);
            File::put($root . '/lang/' . $locale . '.json', json_encode($locales[$locale], JSON_UNESCAPED_UNICODE));
        }

        // an approved transliteration of the brand is not an error
        $ja = array_combine($keys, ['ストライプ決済', 'すべてのメンバーが削除されます。元に戻せません。', '価格（セント）', '{1} 1 件は結果なし。|[0,*] :count 件は結果なし。']);
        File::put($root . '/lang/ja.json', json_encode($ja, JSON_UNESCAPED_UNICODE));
        [$code, $out] = $this->runScript('scripts/i18n_check.php', ['--root=' . $root, '--no-js']);
        $this->assertSame(0, $code, 'approved transliteration: ' . $out);
    }

    public function test_sync_applies_the_fallback_rule_and_never_overwrites_a_translation(): void
    {
        $root = $this->fixture([
            'en' => ['Site Name' => 'Site Name', 'Delete' => 'Delete'],
            'vi' => ['Site Name' => 'Tên trang web', 'Delete' => 'Xóa'],
            'de' => ['Site Name' => 'Site Name', 'Delete' => 'Löschen'],
        ], [['Site name', 'Site Name'], ['Shown in every email.', null], ['Delete', null]]);

        [$code, $out] = $this->runScript('scripts/i18n/sync.php', ['--root=' . $root]);
        $this->assertSame(0, $code, $out);
        $vi = json_decode(File::get($root . '/lang/vi.json'), true);
        $de = json_decode(File::get($root . '/lang/de.json'), true);
        $en = json_decode(File::get($root . '/lang/en.json'), true);

        // exact key -> old label's translation -> English; existing keys untouched
        $this->assertSame('Tên trang web', $vi['Site name']);
        $this->assertSame('Shown in every email.', $vi['Shown in every email.']);
        $this->assertSame('Site name', $de['Site name'], 'an old label left in English is not a translation');
        $this->assertSame('Site name', $en['Site name']);
        $this->assertSame('Tên trang web', $vi['Site Name']);
        $this->assertSame('Xóa', $vi['Delete']);

        File::ensureDirectoryExists($root . '/in');
        File::put($root . '/in/vi.json', json_encode([
            'Site name' => 'Tên trang', 'Shown in every email.' => 'Hiển thị trong mọi email.', 'Delete' => 'Gỡ bỏ',
        ], JSON_UNESCAPED_UNICODE));
        [$code, $out] = $this->runScript('scripts/i18n/sync.php', ['--root=' . $root, '--from=' . $root . '/in']);
        $this->assertSame(0, $code, $out);
        $vi = json_decode(File::get($root . '/lang/vi.json'), true);
        $this->assertSame('Tên trang', $vi['Site name'], 'a fallback value is upgraded by a real translation');
        $this->assertSame('Hiển thị trong mọi email.', $vi['Shown in every email.']);
        $this->assertSame('Xóa', $vi['Delete'], 'a real translation is not overwritten without --overwrite');

        File::put($root . '/in/vi.json', json_encode(['Delete' => 'Xóa :what'], JSON_UNESCAPED_UNICODE));
        [$code, $out] = $this->runScript('scripts/i18n/sync.php', ['--root=' . $root, '--from=' . $root . '/in', '--overwrite']);
        $this->assertSame(3, $code, 'a translation that invents a placeholder is rejected');
        $this->assertSame('Xóa', json_decode(File::get($root . '/lang/vi.json'), true)['Delete']);
    }

    public function test_lang_files_keep_their_format_when_rewritten(): void
    {
        foreach (self::LOCALES as $locale) {
            $path = lang_path($locale . '.json');
            [$data, $eol] = LangFile::read($path);
            $this->assertSame(File::get($path), LangFile::encode($data, $eol), "lang/{$locale}.json is not in the canonical sorted format");
        }
        foreach (JsLangFile::locales(base_path()) as $locale) {
            $path = JsLangFile::path(base_path(), $locale);
            [$data, $eol] = JsLangFile::read($path);
            $this->assertSame(File::get($path), JsLangFile::encode($data, $eol), "resources/js/lang/{$locale}.ts changed style");
        }
    }

    public function test_every_preact_string_is_in_every_locale_bundle(): void
    {
        [$en] = JsLangFile::read(JsLangFile::path(base_path(), 'en'));
        $required = array_intersect_key(Requirements::collectJs(base_path()), $en);
        $this->assertGreaterThan(150, count($required));
        $this->assertArrayHasKey('Skip ad', $required);

        foreach (JsLangFile::locales(base_path()) as $locale) {
            [$data] = JsLangFile::read(JsLangFile::path(base_path(), $locale));
            $this->assertSame([], array_keys(array_diff_key($required, $data)), "missing in resources/js/lang/{$locale}.ts");
            foreach ($required as $key => $meta) {
                $this->assertTrue(Placeholders::same($meta['source'], $data[$key]), "placeholders of \"{$key}\" in {$locale}.ts");
            }
        }

        [$vi] = JsLangFile::read(JsLangFile::path(base_path(), 'vi'));
        $this->assertSame('Bỏ qua quảng cáo', $vi['Skip ad']);
        $this->assertSame('Tên tệp không được vượt quá {max} ký tự', $vi['Your file name does not exceed {max} characters']);
    }

    public function test_every_choice_line_prints_its_number_in_every_locale(): void
    {
        // Laravel picks an implicit form by the locale's plural rule (vi always the first one, ru one of three, ar one of
        // six): a line shaped like the English "1 video|:count videos" would print "1 video" for 5 videos.
        $required = Requirements::collect(base_path());
        $choices = array_filter($required, static fn (array $meta) => Plurals::counted($meta['source']));
        $this->assertGreaterThan(60, count($choices));

        foreach (self::LOCALES as $locale) {
            [$data] = LangFile::read(lang_path($locale . '.json'));
            $wrong = [];
            foreach ($choices as $key => $meta) {
                $numbers = Plurals::wrongNumbers($meta['source'], $data[$key] ?? '', $locale, base_path());
                if ($numbers) {
                    $wrong[$key] = $numbers;
                }
            }
            $this->assertSame([], $wrong, "lang/{$locale}.json: plural lines without the number");
        }

        $this->app->setLocale('vi');
        $this->assertSame('5 video', trans_choice('1 video|:count videos', 5));
        $this->assertSame('1 video', trans_choice('1 video|:count videos', 1));
        $this->app->setLocale('ru');
        $this->assertStringContainsString('21', trans_choice('1 video|:count videos', 21));
        $this->assertStringContainsString('5', trans_choice('1 video|:count videos', 5));
        $this->app->setLocale('ar');
        foreach ([0, 2, 3, 11, 100] as $n) {
            $this->assertStringContainsString((string) $n, trans_choice('1 video|:count videos', $n), "ar {$n}");
        }
    }

    public function test_plural_check_finds_the_lines_laravel_prints_without_the_number(): void
    {
        $english = '1 video|:count videos';
        $this->assertSame(array_values(array_diff(range(0, 120), [1])), Plurals::wrongNumbers($english, '1 video|:count video', 'vi', base_path()));
        $this->assertSame([], Plurals::wrongNumbers($english, ':count video', 'vi', base_path()));
        $this->assertSame([], Plurals::wrongNumbers($english, '{1} 1 video|[0,*] :count video', 'vi', base_path()));
        $this->assertSame([], Plurals::wrongNumbers($english, ':count видео|:count видео|:count видео', 'ru', base_path()));
        // a form used for exactly one number may leave the number out: "once", the Arabic dual
        $this->assertSame([], Plurals::wrongNumbers('Searched once|Searched :count times', '{1} Einmal gesucht|[0,*] :count Mal gesucht', 'de', base_path()));
        $this->assertSame([], Plurals::wrongNumbers($english, ':count فيديو|فيديو واحد|فيديوان|:count فيديوهات|:count فيديو|:count فيديو', 'ar', base_path()));
        $this->assertNotSame([], Plurals::wrongNumbers($english, 'فيديو واحد|:count فيديو', 'ar', base_path()));
        // French takes the first form for 0 too
        $this->assertSame([0], Plurals::wrongNumbers($english, '1 vidéo|:count vidéos', 'fr', base_path()));

        // the fallback for a locale without its own forms is the explicit English line, right in every locale
        $this->assertSame('{1} 1 video|[0,*] :count videos', Plurals::explicit($english));
        $this->assertSame('{0} none|[1,*] :count', Plurals::explicit('{0} none|[1,*] :count'));
        $this->assertSame('One link per line: Name | address.', Plurals::explicit('One link per line: Name | address.'));
        foreach (self::LOCALES as $locale) {
            $this->assertSame([], Plurals::wrongNumbers($english, Plurals::explicit($english), $locale, base_path()), $locale);
        }
        $this->assertTrue(Placeholders::plural($english));
        $this->assertFalse(Placeholders::plural('One link per line: Name | address.'));
        $this->assertSame([':count'], Placeholders::of($english));

        $root = $this->fixture([
            'en' => [$english => $english],
            'vi' => [$english => '1 video|:count video'],
        ], [$english]);
        [$code, $out] = $this->runScript('scripts/i18n_check.php', ['--root=' . $root]);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('vi: plural forms of "1 video|:count videos" print no number for 0, 2, 3', $out);

        File::ensureDirectoryExists($root . '/in');
        File::put($root . '/in/vi.json', json_encode([$english => '1 video|:count video'], JSON_UNESCAPED_UNICODE));
        [$code, $out] = $this->runScript('scripts/i18n/sync.php', ['--root=' . $root, '--from=' . $root . '/in', '--overwrite']);
        $this->assertSame(3, $code, $out);
        $this->assertStringContainsString('trans_choice() would print a form without the number', $out);
        File::put($root . '/in/vi.json', json_encode([$english => ':count video'], JSON_UNESCAPED_UNICODE));
        [$code, $out] = $this->runScript('scripts/i18n/sync.php', ['--root=' . $root, '--from=' . $root . '/in', '--overwrite']);
        $this->assertSame(0, $code, $out);
        $this->assertSame(':count video', json_decode(File::get($root . '/lang/vi.json'), true)[$english]);
    }

    public function test_extractor_reads_constants_nested_label_maps_and_option_texts(): void
    {
        $voices = Extractor::fromCatalog(base_path(), 'App\\Support\\ThemeStructure::VOICES', ['**']);
        $this->assertContains('Tonight in the lounge', $voices);
        $this->assertContains('Top plays', $voices);
        $this->assertNotContains('tokyo_neon', $voices);

        $channel = Extractor::fromCatalog(base_path(), 'App\\Support\\ChannelCustomizationSettings::options', ['**']);
        $this->assertContains('Soft arc', $channel);
        $this->assertContains('Most viewed video', $channel);

        $this->assertSame([], Extractor::fromCatalog(base_path(), 'App\\Support\\ThemeStructure::NO_SUCH_CONSTANT', ['**']));
        $this->assertArrayHasKey('App\\Support\\ThemeStructure::NO_SUCH_CONSTANT', Extractor::$skipped);

        $mail = Extractor::fromConfig(base_path(), 'config/admin_settings.php', ['option_help', 'more_options']);
        $this->assertContains('Your hosting sends them. Easy to set up, but emails often land in spam.', $mail);
        $this->assertContains('Round robin (several services)', $mail);

        $required = Requirements::collect(base_path());
        foreach (['Fastest. Nothing moves or glows.', 'Webhook signing secret', 'Hand-picked tonight', 'Counter only', 'Card in the grid',
            'Site is live', 'Main menu', 'Winter', 'Communication > Mass Broadcast'] as $key) {
            $this->assertArrayHasKey($key, $required, "{$key} is shown through __() and must be a required key");
        }
        // config 'why' notes are internal speed notes, never shown
        $this->assertArrayNotHasKey('Master switch.', $required);
    }

    public function test_extractor_reads_literal_translation_calls_only(): void
    {
        $code = <<<'BLADE'
            {{ __('Save changes') }} @lang("Delete") {{ __('It\'s here', ['x' => 1]) }} {{ trans_choice('{0} none|[1,*] :count', $n) }}
            {{ __("Hello {$name}") }} {{ __('auth.failed') }} {{ __($label) }} {{ __('Prefix ' . $x) }} {{ $obj->__('Not a call') }}
            BLADE;

        $this->assertSame(['Save changes', 'Delete', "It's here", '{0} none|[1,*] :count'], Extractor::fromSource($code, ['auth']));
    }

    /** @return array{0: int, 1: string} */
    private function runScript(string $script, array $args): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 ' . escapeshellarg(base_path($script));
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    /**
     * A throwaway project root: lang/<locale>.json + scripts/i18n/inventory.json (no source paths).
     *
     * @param array<string, array<string, string>> $locales
     * @param array<int, string|array{0: string, 1: ?string}> $strings English keys, or [key, old label]
     */
    private function fixture(array $locales, array $strings): string
    {
        $root = storage_path('framework/testing/i18n-' . bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($root . '/lang');
        File::ensureDirectoryExists($root . '/scripts/i18n');
        foreach ($locales as $locale => $data) {
            File::put($root . '/lang/' . $locale . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        $rows = array_map(static fn ($s) => is_array($s)
            ? ['en' => $s[0], 'where' => 'test', 'old_label' => $s[1], 'source' => 'test']
            : ['en' => $s, 'where' => 'test', 'old_label' => null, 'source' => 'test'], $strings);
        File::put($root . '/scripts/i18n/inventory.json', json_encode(['strings' => $rows]));
        File::put($root . '/scripts/i18n/sources.json', json_encode(['paths' => [], 'config' => (object) []]));
        $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory($root));

        return $root;
    }
}
