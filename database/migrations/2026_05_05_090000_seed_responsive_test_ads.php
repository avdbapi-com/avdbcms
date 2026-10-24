<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach ([
            'ads_enabled' => '1',
            'ads_hide_for_premium' => '1',
            'ad_preroll_enabled' => '1',
            'ad_preroll_duration' => '5',
            'ad_grid_position' => '6',
        ] as $key => $value) {
            $this->put($key, $value);
        }

        foreach ($this->sampleAds() as $key => $value) {
            $this->put($key, $value, false);
        }

        Setting::clearCache(array_merge(
            ['ads_enabled', 'ads_hide_for_premium', 'ad_preroll_enabled', 'ad_preroll_duration', 'ad_grid_position'],
            array_keys($this->sampleAds())
        ), ['monetization']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach ($this->sampleAds() as $key => $value) {
            DB::table('settings')
                ->where('group', 'monetization')
                ->where('key', $key)
                ->whereIn('value', [$value, $this->legacyCode($value)])
                ->update([
                    'value' => '',
                    'updated_at' => now(),
                ]);
        }

        Setting::clearCache(array_keys($this->sampleAds()), ['monetization']);
    }

    /**
     * The same sample as sites installed before 1.2.1 stored it: their demo ads carry the old class name
     * avd-test-ad (resources/sass/app.scss styles both names).
     */
    private function legacyCode(string $code): string
    {
        return str_replace('avd-demo-ad', 'avd-test-ad', $code);
    }

    private function put(string $key, string $value, bool $overwrite = true): void
    {
        $setting = DB::table('settings')
            ->where('key', $key)
            ->first();

        if (! $setting) {
            DB::table('settings')->insert([
                'key' => $key,
                'value' => $value,
                'group' => 'monetization',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if (! $overwrite && trim((string) $setting->value) !== '') {
            return;
        }

        DB::table('settings')
            ->where('key', $key)
            ->update([
                'value' => $value,
                'group' => 'monetization',
                'updated_at' => now(),
            ]);
    }

    /**
     * Demo ad cards for a new site (class avd-demo-ad, styled in resources/sass/app.scss). They only fill empty ad
     * slots, so a site's own codes are never replaced.
     */
    private function sampleAds(): array
    {
        return [
            'ad_code_header' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--leaderboard" href="/premium" rel="sponsored" aria-label="Sponsored AVDBCMS Premium"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>AVDBCMS Premium</strong><small>Cleaner viewing, faster queues, and fewer interruptions.</small></span><span class="avd-demo-ad__cta">Upgrade</span></a>
HTML,
            'ad_code_grid' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--native" href="/premium" rel="sponsored" aria-label="Sponsored creator promotion"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>Creator-ready promotion</strong><small>Native card slot sized for every feed layout.</small></span><span class="avd-demo-ad__cta">Open</span></a>
HTML,
            'ad_code_sidebar' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--sidebar" href="/premium" rel="sponsored" aria-label="Sponsored Premium sidebar"><span class="avd-demo-ad__label">Sponsored</span><span class="avd-demo-ad__copy"><strong>Watch with fewer breaks</strong><small>Premium keeps the player flow cleaner on every device.</small></span><span class="avd-demo-ad__cta">View plans</span></a>
HTML,
            'ad_code_video' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--video" href="/premium" rel="sponsored" aria-label="Sponsored pre-roll"><span class="avd-demo-ad__label">Sponsored</span><span class="avd-demo-ad__copy"><strong>Support uninterrupted discovery</strong><small>Try Premium for a cleaner viewing flow.</small></span><span class="avd-demo-ad__cta">Learn more</span></a>
HTML,
            'ad_code_footer' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--footer" href="/contact" rel="sponsored" aria-label="Sponsored footer banner"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>Advertise on AVDBCMS</strong><small>Responsive footer inventory for campaigns that need broad reach.</small></span><span class="avd-demo-ad__cta">Contact</span></a>
HTML,
            'ad_code_mobile' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--mobile" href="/premium" rel="sponsored" aria-label="Sponsored mobile banner"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>Premium</strong><small>Fewer interruptions.</small></span><span class="avd-demo-ad__cta">Open</span></a>
HTML,
        ];
    }
};
