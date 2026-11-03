<?php

namespace Tests\Feature;

use App\Support\BillingFormat;
use App\Support\Intl;
use App\Support\LocalNumber;
use App\Support\NumberFormat;
use App\Support\SiteDate;
use App\Support\Studio\StudioFormat;
use App\Support\Studio\ZoneLabel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * A host without PHP's intl extension (2 Nov 2026: a demo site answered 500 on its home page right after the update to
 * 1.2.3). The bundled symfony/polyfill-intl-icu defines NumberFormatter and IntlDateFormatter there, but they accept
 * only the "en" locale and throw for every other one ("en@numbers=latn" too), so class_exists() is no test of intl.
 * Every formatter asks App\Support\Intl::available() and otherwise uses its own fallback; here intl is switched off
 * through Intl::$forced, the way a host without the extension answers.
 */
class IntlMissingHostTest extends TestCase
{
    use RefreshDatabase;

    private const LOCALES = ['en', 'ar', 'fr', 'de', 'ja', 'vi', 'pt', 'ru'];

    protected function setUp(): void
    {
        parent::setUp();
        Intl::$forced = false;
        $this->forgetCachedFormatters();
    }

    protected function tearDown(): void
    {
        Intl::$forced = null;
        $this->forgetCachedFormatters();
        parent::tearDown();
    }

    public function test_the_guard_asks_for_the_extension_not_for_the_polyfill_class(): void
    {
        Intl::$forced = null;
        $this->assertSame(extension_loaded('intl'), Intl::available());

        Intl::$forced = false;
        $this->assertFalse(Intl::available());

        // every PHP file and view of the site (1.2.3 refresh: the six formatters were listed by name; a guard added
        // anywhere else went unseen), App\Support\Intl itself aside (its comment names the wrong test)
        $scanned = 0;

        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);
            $scanned++;
            $this->assertDoesNotMatchRegularExpression('/class_exists\(\s*\\\\?(NumberFormatter|IntlDateFormatter)::class/',
                $source, str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).' must not take the polyfill class for the intl extension (ask App\Support\Intl::available())');
        }

        $this->assertGreaterThan(500, $scanned, 'app/ and resources/views were scanned');
    }

    /**
     * The studio's figures without intl: the member's language's separators (App\Support\LocalNumber's table), and no
     * decimals that are zeros, as intl writes them (1.2.3 refresh: "1,234,567" in every language).
     */
    public function test_the_studio_figures_use_the_languages_separators_without_intl(): void
    {
        $expected = [
            'en' => ['1,234,567', '12.5', '12', '1,234,567.5'],
            'ar' => ['1,234,567', '12.5', '12', '1,234,567.5'],
            'de' => ['1.234.567', '12,5', '12', '1.234.567,5'],
            'es' => ['1.234.567', '12,5', '12', '1.234.567,5'],
            'fr' => ["1\u{202F}234\u{202F}567", '12,5', '12', "1\u{202F}234\u{202F}567,5"],
            'id' => ['1.234.567', '12,5', '12', '1.234.567,5'],
            'ja' => ['1,234,567', '12.5', '12', '1,234,567.5'],
            'pt' => ['1.234.567', '12,5', '12', '1.234.567,5'],
            'ru' => ["1\u{00A0}234\u{00A0}567", '12,5', '12', "1\u{00A0}234\u{00A0}567,5"],
            'tr' => ['1.234.567', '12,5', '12', '1.234.567,5'],
            'vi' => ['1.234.567', '12,5', '12', '1.234.567,5'],
        ];

        foreach ($expected as $locale => [$whole, $half, $zeros, $big]) {
            app()->setLocale($locale);
            $this->forgetCachedFormatters();

            $this->assertSame($whole, StudioFormat::number(1234567), $locale);
            $this->assertSame($half, StudioFormat::number(12.5, 1), $locale.': a decimal');
            $this->assertSame($zeros, StudioFormat::number(12.0, 1), $locale.': no decimals that are zeros, as intl');
            $this->assertSame($big, StudioFormat::number(1234567.5, 1), $locale.': both');
            $this->assertSame('0', StudioFormat::number(null), $locale);
            $this->assertSame(LocalNumber::format(1234567), StudioFormat::number(1234567), $locale.': the same separators as the short counts');
            $this->assertStringContainsString($whole, StudioFormat::signedHtml(1234567), $locale);
        }

        // with intl on this machine, the fallback writes what intl writes (the languages whose grouping intl starts at
        // four digits; Spanish groups from five)
        if (! extension_loaded('intl')) {
            $this->markTestIncomplete('intl is not loaded here: the fallback was compared with its table only');
        }

        foreach (array_keys($expected) as $locale) {
            app()->setLocale($locale);
            Intl::$forced = true;
            $this->forgetCachedFormatters();
            $withIntl = [StudioFormat::number(1234567), StudioFormat::number(12.5, 1), StudioFormat::number(12.0, 1), StudioFormat::number(1234567.5, 1)];
            Intl::$forced = false;
            $this->forgetCachedFormatters();
            $without = [StudioFormat::number(1234567), StudioFormat::number(12.5, 1), StudioFormat::number(12.0, 1), StudioFormat::number(1234567.5, 1)];

            $this->assertSame($withIntl, $without, $locale.': without intl as with it');
        }
    }

    /** @return list<string> every .php file under app/ and resources/views, App\Support\Intl aside */
    private function sourceFiles(): array
    {
        $files = [];

        foreach ([app_path(), resource_path('views')] as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $item) {
                if ($item->isFile() && str_ends_with($item->getFilename(), '.php') && $item->getPathname() !== app_path('Support'.DIRECTORY_SEPARATOR.'Intl.php')) {
                    $files[] = $item->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    public function test_numbers_and_dates_are_written_without_intl_in_every_language(): void
    {
        $date = CarbonImmutable::create(2026, 10, 28, 15, 30, 0, 'UTC');

        foreach (self::LOCALES as $locale) {
            app()->setLocale($locale);
            $this->forgetCachedFormatters();

            $this->assertNotSame('', LocalNumber::format(1234567), $locale);
            $this->assertMatchesRegularExpression('/\d/', LocalNumber::short(6552004), $locale);
            $this->assertNotSame('', LocalNumber::compact(1500, $locale), $locale);
            $this->assertMatchesRegularExpression('/\d/', NumberFormat::decimal(1234.5, 1, $locale), $locale);
            $this->assertMatchesRegularExpression('/\d/', NumberFormat::localFileSize(1_090_000, $locale), $locale);
            $this->assertMatchesRegularExpression('/\d/', NumberFormat::currency(19.99, 'EUR', $locale), $locale);
            $this->assertMatchesRegularExpression('/\d/', NumberFormat::money(1999, 'USD', $locale), $locale);
            $this->assertMatchesRegularExpression('/\d/', BillingFormat::percent(0.075, $locale), $locale);
            $this->assertMatchesRegularExpression('/\d/', BillingFormat::money(1999, null, $locale), $locale);
            $this->assertMatchesRegularExpression('/2026/', BillingFormat::month($date), $locale);
            $this->assertMatchesRegularExpression('/2026/', SiteDate::monthYear($date), $locale);
            $this->assertMatchesRegularExpression('/\d/', StudioFormat::number(1234567), $locale);
            $this->assertMatchesRegularExpression('/\d/', StudioFormat::number(12.5, 1), $locale);
            $this->assertMatchesRegularExpression('/\d/', StudioFormat::money(1999, 'EUR'), $locale);
            $this->assertNotSame('', StudioFormat::date($date), $locale);
            $this->assertNotSame('', StudioFormat::month($date), $locale);
            $this->assertNotSame('', ZoneLabel::city(), $locale);
        }

        app()->setLocale('en');
        $this->assertSame('1,234,567', LocalNumber::format(1234567));
        $this->assertSame('1,234,567', StudioFormat::number(1234567));
    }

    public function test_public_pages_answer_without_intl_in_english_and_arabic(): void
    {
        foreach (['en', 'ar'] as $locale) {
            foreach (['/', '/categories', '/trend', '/login'] as $path) {
                $this->forgetCachedFormatters();
                $response = $this->withSession(['app_locale' => $locale])->get($path);
                $this->assertContains($response->getStatusCode(), [200, 302], $locale.' '.$path.' answered '
                    .$response->getStatusCode().' without intl');
            }
        }
    }

    /** Formatters are cached per language in static arrays: start from none, so the fallback path really runs. */
    private function forgetCachedFormatters(): void
    {
        foreach ([BillingFormat::class, LocalNumber::class, NumberFormat::class, SiteDate::class, StudioFormat::class,
            ZoneLabel::class] as $class) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getProperties() as $property) {
                if (! $property->isStatic() || ! $property->hasDefaultValue() || ! is_array($property->getDefaultValue())) {
                    continue;
                }
                $property->setAccessible(true);
                $property->setValue(null, $property->getDefaultValue());
            }
        }
    }
}
