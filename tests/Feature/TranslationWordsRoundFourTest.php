<?php

namespace Tests\Feature;

use App\Support\LabelGap;
use App\Support\NumberUnit;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * 1.2.3 round 4 fix pass, lane I18N: the translation and markup polish the round-3 verifier left open
 * (TL-VERIFY10-2..6) and lane WA routed to the TL area.
 *
 *  - WA: the label mistranslations ja "Edit User:" (held a password hint), ko "Layout:" ("something laboriously
 *    listed"); French "Remove the history of :day?" takes the no-break space before "?" the French texts use; the
 *    translated labels that carry their own colon no longer get a second space from the markup (Chinese "：" + a
 *    space) -> App\Support\LabelGap between the label and its value on the 13 listed lines.
 *  - TL-VERIFY10-6: "works out of the box with zero external keys" reads idiomatically (not "outside the box", not a
 *    literal "zero", "key" = an API key not a keyboard key) in ar, ms, tl, fil, tr, vi.
 *  - TL-VERIFY10-3: the homepage/section row word stays the glossary's (de Reihe, fr rangée, pt fileira, tl/fil hanay,
 *    ru ряд) in the "rows" labels next to the "per row" ones p92 fixed.
 *  - TL-VERIFY10-5: the creator studio has one word per language (ru студия автора, es/pt estudio/estúdio del/do
 *    creador/criador, zh 创作者工作室); the importer keeps the creator word in "automatic creator SVG avatars"; the
 *    footer tagline says upload the site's way (pt) and video the site's word (ja 動画).
 *  - TL-VERIFY10-2: the old Premium labels take the new page's words (ru one word each for uploads and downloads, ja a
 *    row not a column for storage, de Speicher not Lager).
 *
 * Pass 2 (the round-4 verifier's I18N-V1-01..07): the rest of the shifted Japanese run on users/edit, the Japanese
 * 'Surface:' colon and the users/plans edit page titles (LabelGap), every French question's no-break space, the old
 * Premium labels as nouns in every language, the old settings page's 'Fallback Title' and the unit after its number
 * boxes (App\Support\NumberUnit), the member channel form's row word, and the captcha / helpers words of their page.
 *
 * The checks read the merged translation files (lang/<locale>.json), as the round-3 word tests do.
 */
final class TranslationWordsRoundFourTest extends TestCase
{
    private const LOCALES = ['ar', 'de', 'es', 'fil', 'fr', 'hi', 'id', 'ja', 'ko', 'ms', 'pt', 'ru', 'th', 'tl', 'tr', 'vi', 'zh'];

    /** @return array<string, string> */
    private function file(string $locale): array
    {
        return (array) json_decode((string) file_get_contents(lang_path($locale.'.json')), true);
    }

    // ===== WA: label mistranslations + French no-break space =====

    public function test_the_edit_user_and_layout_labels_are_translated_not_misread(): void
    {
        $this->assertSame('ユーザーの編集:', $this->file('ja')['Edit User:'] ?? null, 'ja held a password hint');
        $this->assertSame('레이아웃:', $this->file('ko')['Layout:'] ?? null, 'ko read "something laboriously listed"');
        // the no-colon sibling already had the right word
        $this->assertSame('레이아웃', $this->file('ko')['Layout'] ?? null);
    }

    public function test_the_french_remove_history_question_uses_a_no_break_space(): void
    {
        $value = (string) ($this->file('fr')['Remove the history of :day?'] ?? '');
        $this->assertStringContainsString("\u{00A0}?", $value, 'French puts a no-break space before "?"');
        $this->assertStringNotContainsString(' ?', $value, 'not a plain space before "?"');
        $this->assertStringContainsString(':day', $value, 'the placeholder is kept');
    }

    // ===== WA: the label/value gap is the language's own after a colon (Chinese "：" holds its space) =====

    public function test_a_labels_colon_is_not_followed_by_a_markup_space_in_chinese(): void
    {
        // the helper the lines now use: nothing after the Chinese full-width colon, a space elsewhere
        $this->assertSame('', LabelGap::admin('zh'));
        $this->assertSame('', LabelGap::member('zh'));
        $this->assertSame(' ', LabelGap::admin('en'));
        $this->assertSame(' ', LabelGap::member('en'));

        app()->setLocale('zh');
        $this->assertSame('编辑用户：X', Blade::render("{{ __('Edit User:') }}{{ \App\Support\LabelGap::admin() }}X"));
        $this->assertSame('赞：5', Blade::render("{{ __('Likes:') }}{{ \App\Support\LabelGap::member() }}5"));
        app()->setLocale('en');
        $this->assertSame('Edit User: X', Blade::render("{{ __('Edit User:') }}{{ \App\Support\LabelGap::admin() }}X"));
    }

    public function test_every_routed_colon_line_uses_the_label_gap_helper(): void
    {
        $admin = [
            'admin/users/edit.blade.php' => ['Edit User:'],
            'admin/plans/edit.blade.php' => ['Edit Plan:'],
            'admin/settings/index.blade.php' => ['Layout:', 'Surface:', 'Tokens:'],
            'admin/avdb-import/index.blade.php' => ['Languages:'],
            'admin/ip-lock.blade.php' => [
                'Your address, as this site sees it:',
                'Or, on your server, in the folder of the site, run:',
                'To see how many addresses are on the list and the address of the computer you connect from, run:',
            ],
            'admin/settings/extras/intro-settings-email-smtp.blade.php' => ['Create an app password for your site:'],
            'components/admin/confirm.blade.php' => ['To confirm, type this word:'],
        ];
        $member = [
            'users/videos/index.blade.php' => ['Comments and their replies:', 'Likes:', 'Dislikes:', 'Places in playlists:'],
        ];
        foreach ([['admin', $admin], ['member', $member]] as [$side, $views]) {
            foreach ($views as $view => $keys) {
                $src = (string) file_get_contents(resource_path('views/'.$view));
                foreach ($keys as $key) {
                    $q = "__('".str_replace("'", "\\'", $key)."')";
                    $this->assertStringContainsString($q, $src, "$view still prints $key");
                    // the LabelGap call follows the label (same line), not a literal space
                    $this->assertMatchesRegularExpression(
                        '/'.preg_quote($q, '/').'[^\n]{0,45}LabelGap::'.$side.'\(\)/u',
                        $src,
                        "$view: $key is followed by LabelGap::$side()"
                    );
                }
            }
        }
    }

    public function test_the_daily_hud_copy_toast_uses_the_language_colon_gap(): void
    {
        $src = (string) file_get_contents(resource_path('js/layout/daily-hud.ts'));
        $this->assertStringNotContainsString("texts.copied) + ' '", $src, 'a hard-coded space after the copied colon');
        $this->assertStringNotContainsString("texts.copiedColon + ' '", $src, 'a hard-coded space after the copied colon');
        $this->assertStringContainsString('colonGap(texts.copiedColon', $src, 'uses the language colon gap');
    }

    // ===== TL-VERIFY10-6: "out of the box" / "zero external keys" =====

    public function test_the_captcha_works_out_of_the_box_reads_idiomatically(): void
    {
        $key = 'Built-in math challenge works out of the box with zero external keys.';
        $avoid = [
            'ar' => '/خارج الصندوق/u',     // literal "outside the box"
            'ms' => '/di luar kotak|sifar/u',
            'tl' => '/sa labas ng kahon/u',
            'fil' => '/sa labas ng kahon/u',
            'tr' => '/sıfır/u',            // literal "zero"
            'vi' => '/phím/u',             // a keyboard key, not an API key
        ];
        foreach ($avoid as $locale => $pattern) {
            $value = (string) ($this->file($locale)[$key] ?? '');
            $this->assertNotSame('', $value, $locale);
            $this->assertDoesNotMatchRegularExpression($pattern, $value, "$locale: $value");
        }
    }

    // ===== TL-VERIFY10-3: the section/homepage row word =====

    public function test_the_section_rows_labels_keep_the_row_word(): void
    {
        $keys = ['Featured rows', 'Latest uploads rows', 'Stacked rows', 'Playlist rows',
            'Default Featured Rows', 'Default Latest Uploads Rows', 'Default Playlist Rows',
            'List / wide row', 'Grid card replaces one video slot; wide row places the ad between completed rows.'];
        $avoid = [
            'de' => '/Zeile/u',
            'fr' => '/(?<!en )\blignes?\b/iu',
            'pt' => '/\blinhas?\b/iu',
            'ru' => '/строк/u',
            'tl' => '/\bhilera\b|(?<![a-z])rows?(?![a-z])/iu',
            'fil' => '/\bhilera\b|(?<![a-z])rows?(?![a-z])/iu',
        ];
        $canon = ['de' => '/Reihe/iu', 'fr' => '/rangée/iu', 'pt' => '/fileira/iu', 'ru' => '/ряд/iu', 'tl' => '/hanay/iu', 'fil' => '/hanay/iu'];
        foreach ($avoid as $locale => $pattern) {
            foreach ($keys as $key) {
                $value = $this->file($locale)[$key] ?? null;
                if (! is_string($value)) {
                    continue;
                }
                $this->assertDoesNotMatchRegularExpression($pattern, $value, "$locale $key: $value");
                $this->assertMatchesRegularExpression($canon[$locale], $value, "$locale $key says the row word");
            }
        }
    }

    // ===== TL-VERIFY10-5: creator studio, importer, footer =====

    public function test_the_creator_studio_has_one_word_per_language(): void
    {
        $keys = ['Creator studio helpers',
            'View counting, data clean-up, creator studio helpers and length limits.',
            'Scrollbars, drop-down lists and the creator studio preview.',
            'Video preview in the creator studio'];
        // the creator word is present and the stray variants are gone
        $want = ['ru' => '/студи\p{L}* автора/u', 'es' => '/estudio del creador/iu', 'pt' => '/estúdio do criador/iu', 'zh' => '/创作者工作室/u'];
        $avoid = ['ru' => '/творческой студии|студии Creator/u', 'es' => '/Creator Studio|estudio de creadores/u', 'pt' => '/estúdio de criação/iu', 'zh' => '/创作工作室(?!者)/u'];
        foreach ($want as $locale => $pattern) {
            foreach ($keys as $key) {
                $value = $this->file($locale)[$key] ?? null;
                if (! is_string($value)) {
                    continue;
                }
                $this->assertMatchesRegularExpression($pattern, $value, "$locale $key: $value");
                $this->assertDoesNotMatchRegularExpression($avoid[$locale], $value, "$locale $key: $value");
            }
        }
    }

    public function test_the_importer_keeps_the_creator_word_for_its_avatars(): void
    {
        $key = 'High-volume automated video catalog importer with 64 dynamic categories, automatic creator SVG avatars, 4K quality tags, multi-language tags and native Upload18 iframe video playback.';
        $want = ['de' => '/Ersteller/u', 'ru' => '/автор/u', 'ko' => '/크리에이터/u', 'zh' => '/创作者/u'];
        foreach ($want as $locale => $pattern) {
            $value = (string) ($this->file($locale)[$key] ?? '');
            $this->assertNotSame('', $value, $locale);
            $this->assertMatchesRegularExpression($pattern, $value, "$locale importer: $value");
        }
        $this->assertDoesNotMatchRegularExpression('/생성기/u', (string) ($this->file('ko')[$key] ?? ''), 'ko said generator');
    }

    public function test_the_footer_tagline_says_upload_and_video_the_sites_way(): void
    {
        $key = 'Share and watch amazing videos. Upload original content and connect with creators worldwide.';
        $this->assertDoesNotMatchRegularExpression('/carreg/iu', (string) ($this->file('pt')[$key] ?? ''), 'pt footer: carregar');
        $ja = (string) ($this->file('ja')[$key] ?? '');
        $this->assertStringNotContainsString('ビデオ', $ja, 'ja footer: ビデオ');
        $this->assertStringContainsString('動画', $ja, 'ja footer: 動画');
    }

    // ===== TL-VERIFY10-2: the old Premium labels =====

    public function test_the_old_premium_labels_take_the_new_pages_words(): void
    {
        // ru: one word each, no longer the same for uploads and downloads
        $ru = $this->file('ru');
        $this->assertNotSame($ru['Uploads Feature'] ?? null, $ru['Downloads Feature'] ?? 'x', 'ru upload == download');
        $this->assertStringNotContainsString('Загружает', (string) ($ru['Uploads Row Label'] ?? ''), 'ru read it as a verb');
        // ja: a row (行), not a column (列), for storage
        $ja = (string) ($this->file('ja')['Storage Row'] ?? '');
        $this->assertStringContainsString('行', $ja);
        $this->assertStringNotContainsString('列', $ja);
        // de: the storage word is Speicher, as on the new page
        $de = (string) ($this->file('de')['Storage Row'] ?? '');
        $this->assertStringContainsString('Speicher', $de);
        $this->assertStringNotContainsString('Lager', $de);
    }

    // ===== pass 2 (round-4 verifier I18N-V1-01..07) =====

    /** I18N-V1-01: each key of the Japanese run on admin/users/edit held the next key's translation. */
    public function test_the_users_edit_quick_actions_take_their_own_japanese_words(): void
    {
        $ja = $this->file('ja');
        $want = [
            'Edit User:' => 'ユーザーの編集:',
            'Login As This User' => 'このユーザーとしてログイン',
            'View Watch History' => '視聴履歴を表示',
            'View Interactions' => 'インタラクションを表示',
            'View Sessions' => 'セッションを表示',
        ];
        foreach ($want as $key => $value) {
            $this->assertSame($value, $ja[$key] ?? null, "ja $key");
        }
        // the hint under New Password says to leave it blank to keep the current password (not "log in as this user")
        $hint = (string) ($ja['(leave blank to keep current)'] ?? '');
        $this->assertStringContainsString('パスワード', $hint, 'ja (leave blank to keep current): '.$hint);
        $this->assertStringNotContainsString('ログイン', $hint);
        // and no two buttons of the page share a label any more
        $run = array_merge(array_keys($want), ['(leave blank to keep current)']);
        $values = array_map(fn (string $k): string => (string) ($ja[$k] ?? ''), $run);
        $this->assertSame(count($values), count(array_unique($values)), 'ja: two keys of the run share a value');
        // zh: "current" is the current password, not the latest (最新)
        $this->assertStringNotContainsString('最新', (string) ($this->file('zh')['(leave blank to keep current)'] ?? ''));
    }

    /**
     * I18N-V1-02: the gap after a label's colon is chosen by language (LabelGap: nothing after the Chinese "：", a space
     * elsewhere), so a label followed by that gap ends in the colon of its language: only Chinese takes the full-width
     * colon ("表面：" + a space read "表面： Glass" in Japanese).
     */
    public function test_a_label_before_the_language_gap_ends_in_its_languages_colon(): void
    {
        $keys = ['Edit User:', 'Edit Plan:', 'Layout:', 'Surface:', 'Tokens:', 'Languages:', 'Your address, as this site sees it:',
            'Or, on your server, in the folder of the site, run:',
            'To see how many addresses are on the list and the address of the computer you connect from, run:',
            'Create an app password for your site:', 'To confirm, type this word:',
            'Comments and their replies:', 'Likes:', 'Dislikes:', 'Places in playlists:'];
        foreach (self::LOCALES as $locale) {
            if ($locale === 'zh') {
                continue;
            }
            $file = $this->file($locale);
            foreach ($keys as $key) {
                $value = $file[$key] ?? null;
                if (is_string($value)) {
                    $this->assertStringEndsNotWith('：', rtrim($value), "$locale $key: $value");
                }
            }
        }
        $this->assertSame('表面:', $this->file('ja')['Surface:'] ?? null, 'ja: the colon of its sibling レイアウト:');
    }

    /** I18N-V1-02 (b): the users/plans edit page titles (<title>) join the label and the name with the language's gap. */
    public function test_the_edit_page_titles_put_the_language_gap_after_the_colon(): void
    {
        foreach (['admin/users/edit.blade.php' => ['Edit User:', '$user->username'], 'admin/plans/edit.blade.php' => ['Edit Plan:', '$plan->name']] as $view => [$key, $name]) {
            $src = (string) file_get_contents(resource_path('views/'.$view));
            $this->assertStringContainsString("@section('title', __('$key') . \\App\\Support\\LabelGap::admin() . $name)", $src, $view);
            $this->assertStringNotContainsString("__('$key') . ' '", $src, "$view: a hard-coded space after the colon");
        }
        app()->setLocale('zh');
        $this->assertSame('编辑用户：jdoe', Blade::render("{{ __('Edit User:') . \\App\\Support\\LabelGap::admin() . 'jdoe' }}"));
        $this->assertSame('编辑计划：Gold', Blade::render("{{ __('Edit Plan:') . \\App\\Support\\LabelGap::admin() . 'Gold' }}"));
        app()->setLocale('en');
        $this->assertSame('Edit User: jdoe', Blade::render("{{ __('Edit User:') . \\App\\Support\\LabelGap::admin() . 'jdoe' }}"));
    }

    /**
     * I18N-V1-03: every French question puts a no-break space before "?" (161 already did; the History page's today /
     * yesterday / clear-all / watched questions and 55 others did not). Keys no source uses are not shown anywhere and are
     * listed (an earlier pass's leftovers); the '?' of a corrupted orphan value ('Ad Code ? Footer') is not a question.
     */
    public function test_every_french_question_puts_a_no_break_space_before_the_question_mark(): void
    {
        $unused = ['Delete your comment permanently ?', 'Do you want to report this content ?', "Don't like this comment ?",
            'Like this comment ?', 'Permanently delete comment ?', 'Pin this comment ?', 'Ready to share your talents with the world ?',
            'Want to download this video ?', 'Want to reply to this comment ?', 'Want to subscribe to this channel ?', 'Want to watch this later?'];
        $fr = $this->file('fr');
        foreach (['Clear all history?', "Remove today's history?", "Remove yesterday's history?", 'Remove watched videos?', 'Remove the history of :day?'] as $key) {
            $this->assertStringContainsString("\u{00A0}?", (string) ($fr[$key] ?? ''), "fr $key");
        }
        $plain = [];
        foreach ($fr as $key => $value) {
            if (! is_string($value) || ! str_contains((string) $key, '?') || in_array($key, $unused, true)) {
                continue;
            }
            if (preg_match('/[^\s\x{00A0}\x{202F}] \?/u', $value) === 1) {
                $plain[] = $key;
            }
        }
        $this->assertSame([], $plain, 'French questions with a plain space before "?"');
    }

    /** I18N-V1-04: the old Premium labels read as nouns (the new page's words), not as verbs, in every language. */
    public function test_the_old_premium_labels_are_nouns_not_verbs(): void
    {
        $keys = ['Uploads Feature', 'Downloads Feature', 'Uploads Row', 'Downloads Row', 'Storage Row', 'Ads Row',
            'Uploads Row Label', 'Downloads Row Label', 'Storage Row Label', 'Ads Row Label'];
        $verbs = [
            'de' => '/Lädt|hoch$|herunterladen/u',
            'hi' => '/करता है/u',
            'th' => '/^(?:อัปโหลด|ดาวน์โหลด)|เก็บของ/u',
            'tl' => '/Nag-upload|Nagda-download|(?<![a-z])Row(?![a-z])/u',
            'fil' => '/Nag-upload|Nagda-download|(?<![a-z])Row(?![a-z])/u',
            'tr' => '/Yükler$|İndirir$|Sırası/u',
            'ar' => '/^تنزيل صف/u',
            'id' => '/^Unduh |^Unggah /u',
            'ko' => '/라벨 업로드$|라벨 다운로드$/u',
            'ru' => '/Загружает|Скачивает/u',
        ];
        foreach ($verbs as $locale => $pattern) {
            $file = $this->file($locale);
            foreach ($keys as $key) {
                $value = $file[$key] ?? null;
                if (is_string($value)) {
                    $this->assertDoesNotMatchRegularExpression($pattern, $value, "$locale $key: $value");
                }
            }
        }
        // uploads and downloads have their own word in each language
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            foreach (['Feature', 'Row', 'Row Label'] as $tail) {
                $this->assertNotSame($file["Uploads $tail"] ?? 'u', $file["Downloads $tail"] ?? 'd', "$locale Uploads/Downloads $tail");
            }
        }
    }

    /**
     * I18N-V1-05 (TL-VERIFY10-4): on the old settings page 'Fallback Title' sits under 'Fallback Kicker' ("small line when
     * the row is empty"): it is the title shown when the row is empty, the words of 'Creators row: title when empty'.
     */
    public function test_the_fallback_title_is_the_title_shown_when_the_row_is_empty(): void
    {
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            $value = (string) ($file['Fallback Title'] ?? '');
            $this->assertDoesNotMatchRegularExpression('/fallback|フォールバック|फ़ॉलबैक|Ersatz|secours|Geri Dönme|alternativo|substituto|Резервн|備用|备用|dự phòng|ทางเลือก|Pengganti|احتياطي|대체/iu', $value, "$locale: $value");
            $whole = (string) ($file['Creators row: title when empty'] ?? '');
            $this->assertMatchesRegularExpression('/[:：]\s*(.+)$/u', $whole, $locale);
            preg_match('/[:：]\s*(.+)$/u', $whole, $m);
            $this->assertSame(mb_strtolower(trim($m[1])), mb_strtolower($value), "$locale Fallback Title = the title when the row is empty");
        }
    }

    /**
     * I18N-V1-05 (TL-VERIFY10-4): the old settings page prints the unit after its day / ms / px number boxes, as the new
     * admin does (config/admin_settings.php 'suffix'), unless the label already names it (the English old labels).
     */
    public function test_the_old_settings_page_prints_the_unit_after_its_number_boxes(): void
    {
        $fields = [
            'home_widget_motion_duration_ms' => ['Duration Ms', 'ms'],
            'home_widget_motion_distance_px' => ['Distance Px', 'px'],
            'home_widget_motion_stagger_ms' => ['Stagger Ms', 'ms'],
            'smart_discovery_rail_lookback_days' => ['Discovery Window Days', 'days'],
            'creator_spotlight_lookback_days' => ['Creator Lookback Days', 'days'],
            'trending_collections_lookback_days' => ['Actor Lookback Days', 'days'],
            'view_history_retention_days' => ['Raw View Retention Days', 'days'],
            'creator_quality_pulse_lookback_days' => ['Pulse Lookback Days', 'days'],
            'studio_health_alerts_lookback_days' => ['Alert Lookback Days', 'days'],
            'creator_recovery_checklist_inactivity_days' => ['Inactivity Days', 'days'],
        ];
        $catalog = (array) config('admin_settings.settings', []);
        $src = (string) file_get_contents(resource_path('views/admin/settings/index.blade.php'));
        foreach ($fields as $name => [$label, $unit]) {
            // the unit is the new admin's suffix of the same setting
            if (isset($catalog[$name]['suffix'])) {
                $this->assertSame($unit, $catalog[$name]['suffix'], $name);
            }
            $this->assertMatchesRegularExpression(
                '/<div class="input-group"><input id="'.preg_quote($name, '/').'" type="number" [^\n]*required>\{\{ \\\\App\\\\Support\\\\NumberUnit::suffix\(\''.preg_quote($label, '/').'\', \''.$unit.'\'\) \}\}<\/div>/u',
                $src,
                "$name: the unit after the box"
            );
            // English: the old label names the unit ("... Days", "... Ms"): nothing is added
            $this->assertSame('', NumberUnit::after($label, $unit, 'en'), "en $label");
            foreach (self::LOCALES as $locale) {
                $this->assertSame(trim((string) __($unit, [], $locale)), NumberUnit::after($label, $unit, $locale), "$locale $label: the unit after the box");
            }
        }
        $this->assertSame('<span class="input-group-text">дн.</span>', NumberUnit::suffix('Discovery Window Days', 'days', 'ru')->toHtml());
        $this->assertSame('', NumberUnit::suffix('Discovery Window Days', 'days', 'en')->toHtml());
        // a short unit inside a word is not the unit ("d" in "periodo"); a word of its own is
        $this->assertSame('', NumberUnit::after('Days', 'days', 'en'));
    }

    /** I18N-V1-06: the creator's channel form: each "rows" field takes the row word of its "per row" pair. */
    public function test_the_member_channel_form_rows_take_the_row_word_of_their_pair(): void
    {
        $avoid = ['pt' => '/\blinhas?\b/iu', 'fr' => '/(?<!en )\blignes?\b/iu', 'de' => '/Zeile/u', 'ru' => '/строк/u', 'tl' => '/\bhilera\b/iu', 'fil' => '/\bhilera\b/iu'];
        $canon = ['pt' => '/fileira/iu', 'fr' => '/rangée/iu', 'de' => '/Reihe/u', 'ru' => '/ряд/u', 'tl' => '/hanay/iu', 'fil' => '/hanay/iu'];
        foreach ($avoid as $locale => $pattern) {
            $file = $this->file($locale);
            foreach (['Featured: rows', 'Latest uploads: rows', 'Playlists: rows', 'Featured: per row', 'Latest uploads: per row', 'Playlists: per row'] as $key) {
                $value = (string) ($file[$key] ?? '');
                $this->assertDoesNotMatchRegularExpression($pattern, $value, "$locale $key: $value");
                $this->assertMatchesRegularExpression($canon[$locale], $value, "$locale $key: $value");
            }
        }
        // the ad help names the 'Cinema rows' layout by its own name
        $help = 'Used by the Spotlight, Cinema rows, Mosaic, Shorts and Control room homepage layouts. Leave empty to use no ad there.';
        foreach (['tl', 'fil'] as $locale) {
            $this->assertStringContainsString((string) $this->file($locale)['Cinema rows'], (string) ($this->file($locale)[$help] ?? ''), $locale);
        }
    }

    /** I18N-V1-07: the captcha, the helpers card and the grid-card help use the words of their own page. */
    public function test_the_captcha_helpers_and_grid_texts_use_their_pages_words(): void
    {
        $captcha = 'Built-in math challenge works out of the box with zero external keys.';
        // ms: an API key is "kunci" (as 'Kunci Tapak Captcha'), "kekunci" is a keyboard key
        $ms = $this->file('ms');
        $this->assertStringNotContainsString('kekunci', mb_strtolower((string) ($ms[$captcha] ?? '')));
        foreach (['Connect at least one. Your keys are saved to the .env file and never shown again.', 'Its keys or webhook are missing.',
            "Stored in your site's .env file. Keys are never shown again after saving.", 'Search keys or translations...'] as $key) {
            if (isset($ms[$key])) {
                $this->assertStringNotContainsString('kekunci', mb_strtolower((string) $ms[$key]), "ms $key");
            }
        }
        $this->assertStringContainsString('kekunci', mb_strtolower((string) ($ms['Keyboard shortcuts'] ?? 'kekunci')), 'ms keyboard keys stay kekunci');
        // tr: the challenge is "meydan okuma", as the provider option 'Built-in challenge (no setup)'
        $tr = $this->file('tr');
        $this->assertStringContainsString('meydan okuma', mb_strtolower((string) ($tr['Built-in challenge (no setup)'] ?? '')));
        $this->assertStringContainsString('meydan okuma', mb_strtolower((string) ($tr[$captcha] ?? '')));
        $this->assertStringNotContainsString('yarışma', (string) ($tr[$captcha] ?? ''));
        // Settings > Advanced: the card title's helpers word is the page description's
        $word = ['es' => 'ayudas', 'pt' => 'ajudas', 'zh' => '助手', 'id' => 'bantuan', 'vi' => 'công cụ hỗ trợ'];
        foreach ($word as $locale => $w) {
            $file = $this->file($locale);
            $this->assertStringContainsString($w, mb_strtolower((string) ($file['Creator studio helpers'] ?? '')), "$locale card title");
            $this->assertStringContainsString($w, mb_strtolower((string) ($file['View counting, data clean-up, creator studio helpers and length limits.'] ?? '')), "$locale page description");
        }
        // no capital letter after the semicolon
        $grid = 'Grid card replaces one video slot; wide row places the ad between completed rows.';
        foreach (self::LOCALES as $locale) {
            $value = (string) ($this->file($locale)[$grid] ?? '');
            $this->assertDoesNotMatchRegularExpression('/[;；]\s*\p{Lu}/u', $value, "$locale: $value");
        }
    }

    /**
     * TL-VERIFY10-2, the rest: inside the old Premium section one row word and one label word per language, and on Money >
     * Premium page text a row's switch and its row text quote the row with the word of the public row's own label
     * ('Video Storage Space', 'Ads on videos').
     */
    public function test_the_premium_rows_name_each_row_one_way(): void
    {
        $rows = ['Uploads Row', 'Downloads Row', 'File Size Row', 'Storage Row', 'Ads Row'];
        $labels = ['Uploads Row Label', 'Downloads Row Label', 'File Size Row Label', 'Storage Row Label', 'Ads Row Label'];
        $rowWord = ['de' => '/^Zeile „/u', 'fr' => '/^Ligne /u', 'tr' => '/Satırı$/u', 'ms' => '/^Baris /u', 'tl' => '/^Hanay /u', 'fil' => '/^Hanay /u'];
        $labelWord = ['de' => '/^Beschriftung der Zeile „/u', 'fr' => '/^Libellé de la ligne /u', 'tr' => '/Satırı Etiketi$/u', 'ko' => '/ 행 라벨$/u', 'tl' => '/^Label ng Hanay /u', 'fil' => '/^Label ng Hanay /u'];
        foreach ($rowWord as $locale => $pattern) {
            foreach ($rows as $key) {
                $this->assertMatchesRegularExpression($pattern, (string) ($this->file($locale)[$key] ?? ''), "$locale $key");
            }
        }
        foreach ($labelWord as $locale => $pattern) {
            foreach ($labels as $key) {
                $this->assertMatchesRegularExpression($pattern, (string) ($this->file($locale)[$key] ?? ''), "$locale $key");
            }
        }
        // the switch, the row text and the old page say the public row's word
        $same = [
            'de' => ['Speicherplatz' => ['Comparison row: storage', 'Premium page: Storage row text', 'Storage Row', 'Storage Row Label', 'Video Storage Space'],
                'Werbung' => ['Comparison row: ads', 'Premium page: Ads row text', 'Ads Row', 'Ads Row Label', 'Ads on videos']],
            'fr' => ['publicités' => ['Comparison row: ads', 'Premium page: Ads row text', 'Ads Row', 'Ads Row Label', 'Ads on videos']],
            'hi' => ['स्टोरेज' => ['Comparison row: storage', 'Premium page: Storage row text', 'Storage Row', 'Storage Row Label', 'Video Storage Space']],
            'ko' => ['저장 공간' => ['Comparison row: storage', 'Premium page: Storage row text', 'Storage Row', 'Storage Row Label', 'Video Storage Space']],
            'th' => ['พื้นที่จัดเก็บ' => ['Comparison row: storage', 'Premium page: Storage row text', 'Storage Row', 'Storage Row Label', 'Video Storage Space']],
        ];
        foreach ($same as $locale => $words) {
            $file = $this->file($locale);
            foreach ($words as $word => $keys) {
                foreach ($keys as $key) {
                    $this->assertStringContainsString(mb_strtolower($word), mb_strtolower((string) ($file[$key] ?? '')), "$locale $key");
                }
            }
        }
        $this->assertDoesNotMatchRegularExpression('/Anzeigen/u', (string) ($this->file('de')['Comparison row: ads'] ?? ''), 'de: Anzeigen also reads "show"');
        // tl/fil: the row text is Tagalog, not "Text ng row ng storage"
        foreach (['tl', 'fil'] as $locale) {
            foreach (['Uploads', 'Downloads', 'File size', 'Storage', 'Ads'] as $row) {
                $value = (string) ($this->file($locale)["Premium page: $row row text"] ?? '');
                $this->assertDoesNotMatchRegularExpression('/(?<![a-z])(Text|row|storage)(?![a-z])/u', $value, "$locale $row: $value");
            }
        }
        // tr: the capital of "indirmeler" is İ
        $this->assertStringContainsString('İndirmeler', (string) ($this->file('tr')['Premium page: Downloads row text'] ?? ''));
    }
}
