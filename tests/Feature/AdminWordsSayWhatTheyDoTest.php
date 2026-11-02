<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1.2.3 round 3 pre-fix pass (lane TR, second pass): admin words that said something else in many languages, found
 * next to the forms of "you" (R2R-04) and "Dismiss" (R2R-13).
 *
 *  - "Leave on." (keep this setting switched on: Settings > SEO, Security, Advanced, and two card descriptions) read
 *    "go away" (fr "Partez." / "partir", fil and tl "umalis ka na"), "continue" (zh "继续吧", "继续前往成人网站" = go on to
 *    adult sites, ko "계속하세요", de "weitermachen", tr "Devam et."), "abandon it" (id and ms "Tinggalkan", th "ทิ้งไว้"),
 *    "on top of" (hi "पर छोड़ दो") or half a sentence (ar "ترك على", pt "Deixe."). Each language now says "keep it on".
 *  - Community > Reports: the Dismissed tab and status, the row menu's "Dismiss: nothing wrong" and its "Report
 *    dismissed" toast read "fired" (de "Entlassen", pt "Demitir"), "removed from office" (tr "Görevden alındı"), "laid
 *    off" (id "Diberhentikan"), "get rid of" (id "Singkirkan"), "ignore" (ar "تجاهل") or stayed English (fil and tl
 *    "Nadismiss", "Dismiss: walang mali"). A report is closed with no action: each language's word for that.
 *  - "Run now" / "Run Now" (start a scheduled task or an import at once) and "Run already finished." (an import run)
 *    read "run" as on foot in 11 languages (ar "اركض", de "Lauf jetzt", es "Corre", fr "Courez", pt "Corra", ru "Беги",
 *    tr "koş", th "วิ่ง", ms "Lari", hi "भागो", fil and tl "Takbo").
 *
 * The checks read the merged translation files (lang/<locale>.json), as the other language tests do.
 */
class AdminWordsSayWhatTheyDoTest extends TestCase
{
    use RefreshDatabase;

    /** In every "Leave on" text of a language, the words that say "keep it switched on" there. */
    private const KEEP_IT_ON = [
        'ar' => 'مفعّل', 'de' => '/lassen sie/i', 'es' => 'activad', 'fil' => 'naka-on', 'fr' => 'activé', 'hi' => 'चालू',
        'id' => 'aktif', 'ja' => 'オンのまま', 'ko' => '켜', 'ms' => 'aktif', 'pt' => '/ativad|ligad/', 'ru' => 'включ',
        'th' => '/เปิด.*ไว้/', 'tl' => 'naka-on', 'tr' => '/açık bırakın/i', 'vi' => 'bật', 'zh' => '开启',
    ];

    /** "go away", "continue", "abandon", "leave ... on (top of)": what "Leave" must not read in a setting's help. */
    private const NOT_LEAVE = [
        'ar' => '/ترك على|اتركهم على|غادر/u', 'de' => '/weitermachen|verlassen/iu', 'fil' => '/umalis/iu',
        'fr' => '/(?<!\p{L})(partez|partir|quittez|quitter)(?!\p{L})/iu', 'hi' => '/पर छोड़ दो|भागो/u', 'id' => '/tinggalkan/iu',
        'ko' => '/떠나|계속하/u', 'ms' => '/tinggalkan/iu', 'th' => '/ทิ้ง|ออกไป/u', 'tl' => '/umalis/iu', 'tr' => '/devam e/iu',
        'zh' => '/继续|离开/u',
    ];

    /** What a dismissed report must not read: fired, removed from office, laid off, got rid of, ignored, or English. */
    private const NOT_DISMISSED = '/entlass|görevden|diberhentikan|demiti|singkirkan|ismiss|تجاهل|해고|despedi|miễn nhiệm|membubarkan/iu';

    /** "Run" as on foot. */
    private const NOT_RUN = '/اركض|lauf jetzt|corre|courez|corra|беги|(?<!\p{L})koş|วิ่ง|(?<!\p{L})lari(?!\p{L})|भागो|दौड़|(?<!\p{L})(takbo|tumakbo|pagtakbo)(?!\p{L})/iu';

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2']);
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    /** @return list<string> */
    private function locales(): array
    {
        return array_values(array_diff(array_unique(array_merge(array_keys((array) config('languages')), ['fil'])), ['en']));
    }

    /** @return array<string, mixed> */
    private function file(string $locale): array
    {
        return (array) json_decode((string) file_get_contents(lang_path($locale.'.json')), true);
    }

    /** @return list<string> the keys of the texts the sources use (scripts/i18n Requirements) */
    private function required(): array
    {
        require_once base_path('scripts/i18n/I18n.php');

        return array_keys(\Avd\I18n\Requirements::collect(base_path()));
    }

    public function test_leave_on_says_keep_it_switched_on_in_every_language(): void
    {
        $keys = array_values(array_filter($this->required(), fn (string $key): bool => (bool) preg_match('/\bleave (it |them |these )?on\b/i', $key)));
        $this->assertGreaterThanOrEqual(11, count($keys), 'the "Leave on" help texts of Settings: '.implode(' | ', $keys));
        $this->assertContains('A list of all your pages that helps Google find them. Leave on.', $keys);
        $this->assertContains('Small conveniences. Leave them on.', $keys);

        $wrong = [];
        foreach ($this->locales() as $locale) {
            $file = $this->file($locale);
            $marker = self::KEEP_IT_ON[$locale] ?? null;
            $this->assertNotNull($marker, $locale);
            foreach ($keys as $key) {
                $value = $file[$key] ?? null;
                if (! is_string($value) || $value === $key) {
                    $wrong[] = "{$locale} {$key}: not translated";

                    continue;
                }
                $keeps = str_starts_with($marker, '/') ? (bool) preg_match($marker.'u', $value) : str_contains($value, $marker);
                if (! $keeps) {
                    $wrong[] = "{$locale} {$key}: {$value}";
                }
                if (isset(self::NOT_LEAVE[$locale]) && preg_match(self::NOT_LEAVE[$locale], $value, $m)) {
                    $wrong[] = "{$locale} {$key}: \"{$m[0]}\" in {$value}";
                }
            }
            // keep the value "*" of Developer tools > CORS: not "go away *" nor "abandon *"
            $star = $file['Leave * unless your developer says otherwise.'] ?? '';
            if (isset(self::NOT_LEAVE[$locale]) && preg_match(self::NOT_LEAVE[$locale], $star, $m)) {
                $wrong[] = "{$locale} Leave * unless ...: \"{$m[0]}\" in {$star}";
            }
        }

        $this->assertSame([], $wrong, '"Leave on" tells the admin to keep the setting switched on');
    }

    public function test_a_dismissed_report_is_closed_without_action_in_every_language(): void
    {
        $wrong = [];
        foreach ($this->locales() as $locale) {
            $file = $this->file($locale);
            foreach (['Dismissed', 'Dismiss: nothing wrong', 'Report dismissed'] as $key) {
                $value = $file[$key] ?? null;
                if (! is_string($value) || $value === $key || preg_match(self::NOT_DISMISSED, $value)) {
                    $wrong[] = "{$locale} {$key}: ".var_export($value, true);
                }
            }
        }

        $this->assertSame([], $wrong, 'a report closed with no action, not a fired one');
    }

    public function test_run_now_starts_the_task_in_every_language(): void
    {
        $wrong = [];
        foreach ($this->locales() as $locale) {
            $file = $this->file($locale);
            foreach (['Run now', 'Run Now', 'Run already finished.'] as $key) {
                $value = $file[$key] ?? null;
                if (! is_string($value) || $value === $key || preg_match(self::NOT_RUN, $value)) {
                    $wrong[] = "{$locale} {$key}: ".var_export($value, true);
                }
            }
        }

        $this->assertSame([], $wrong, '"Run now" starts a task, nobody runs on foot');
    }

    public function test_the_admin_pages_show_these_words_in_the_admins_language(): void
    {
        $admin = User::factory()->admin()->create();

        // Community > Reports: the Dismissed tab, the row menu (a template) and its toast
        foreach (['de' => ['Abgewiesen', 'Abweisen: kein Verstoß', 'Meldung abgewiesen'], 'tr' => ['Reddedildi', 'Reddet: sorun yok', 'Şikayet reddedildi'], 'tl' => ['Ibinasura', 'Ibasura: walang mali', 'Ibinasura ang ulat']] as $locale => $words) {
            $html = (string) $this->actingAs($admin)->withSession(['app_locale' => $locale])->get(route('admin.reports.index'))->assertOk()->getContent();
            foreach ($words as $word) {
                $this->assertStringContainsString(e($word), $html, $locale);
            }
            $this->assertDoesNotMatchRegularExpression(self::NOT_DISMISSED, strip_tags($html), $locale);
        }

        // Settings > SEO: the sitemaps', the adult rating's and the canonical address's help (1.2.2's words gone; the page
        // has other texts that rightly say "continue", such as its confirm dialog's "您确定要继续吗？")
        $seo = [
            'fr' => ['Laissez activé.', ['Partez.', 'Laisser pour les sites adultes.']],
            'zh' => ['请保持开启。', ['继续吧', '继续前往成人网站']],
            'tr' => ['Açık bırakın.', ['Devam et.', 'siteler için bırakın']],
        ];
        foreach ($seo as $locale => [$words, $gone]) {
            $html = (string) $this->actingAs($admin)->withSession(['app_locale' => $locale])->get(route('admin.settings.page', ['page' => 'seo']))->assertOk()->getContent();
            $this->assertStringContainsString(e($words), $html, $locale);
            foreach ($gone as $old) {
                $this->assertStringNotContainsString(e($old), $html, $locale);
            }
        }
    }
}
