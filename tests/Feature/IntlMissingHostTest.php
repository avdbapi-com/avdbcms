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

        foreach (['app/Support/BillingFormat.php', 'app/Support/LocalNumber.php', 'app/Support/NumberFormat.php',
            'app/Support/SiteDate.php', 'app/Support/Studio/StudioFormat.php', 'app/Support/Studio/ZoneLabel.php'] as $file) {
            $source = (string) file_get_contents(base_path($file));
            $this->assertDoesNotMatchRegularExpression('/class_exists\(\s*\\\\?(NumberFormatter|IntlDateFormatter)::class/',
                $source, $file.' must not take the polyfill class for the intl extension');
        }
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
