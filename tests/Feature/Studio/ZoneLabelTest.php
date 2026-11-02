<?php

namespace Tests\Feature\Studio;

use App\Support\Studio\StudioFormat;
use App\Support\Studio\ZoneLabel;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * 1.2.3 (member design 6.4): a scheduled time is shown with the site zone's offset ON THAT
 * DATE (Paris is GMT+2 until the last Sunday of October at 03:00, GMT+1 after), the city name and "now" in that zone;
 * studio numbers use Western digits in every language (perf-19).
 */
class ZoneLabelTest extends TestCase
{
    public function test_the_offset_follows_summer_time_on_the_date_itself(): void
    {
        config(['app.timezone' => 'Europe/Paris']);

        $this->assertSame('GMT+2', ZoneLabel::offset(CarbonImmutable::parse('2026-10-24 20:00', 'Europe/Paris')));
        $this->assertSame('GMT+1', ZoneLabel::offset(CarbonImmutable::parse('2026-11-06 20:00', 'Europe/Paris')));
        $this->assertSame('20:00 GMT+1', ZoneLabel::for(CarbonImmutable::parse('2026-11-06 19:00', 'UTC')));
        $this->assertSame('Paris', ZoneLabel::city());
    }

    public function test_half_hour_zones_and_utc(): void
    {
        config(['app.timezone' => 'Asia/Kolkata']);
        $this->assertSame('GMT+5:30', ZoneLabel::offset(CarbonImmutable::parse('2026-11-06 20:00', 'Asia/Kolkata')));
        $this->assertSame('Kolkata', ZoneLabel::city());

        config(['app.timezone' => 'UTC']);
        $this->assertSame('GMT', ZoneLabel::offset(CarbonImmutable::parse('2026-11-06 20:00', 'UTC')));

        config(['app.timezone' => 'America/Sao_Paulo']);
        $this->assertSame('GMT-3', ZoneLabel::offset(CarbonImmutable::parse('2026-11-06 20:00', 'America/Sao_Paulo')));
        $this->assertSame('Sao Paulo', ZoneLabel::city());
    }

    public function test_a_broken_zone_falls_back_to_utc(): void
    {
        config(['app.timezone' => 'Not/AZone']);

        $this->assertSame('UTC', ZoneLabel::zone()->getName());
    }

    public function test_the_goes_public_line_names_the_day_the_time_and_the_offset(): void
    {
        config(['app.timezone' => 'Europe/Paris', 'site.date_format' => 'M d, Y']);
        app()->setLocale('en');

        $this->assertSame('Goes public Nov 6, 2026, 20:00 GMT+1', ZoneLabel::goesPublic(CarbonImmutable::parse('2026-11-06 20:00', 'Europe/Paris')));
    }

    public function test_studio_numbers_use_western_digits_in_every_language(): void
    {
        foreach (['ar', 'hi', 'th', 'fr', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertMatchesRegularExpression('/^14\D?260$/u', StudioFormat::number(14260), $locale);
        }

        app()->setLocale('en');
        $this->assertSame('14,260', StudioFormat::number(14260));
    }
}
