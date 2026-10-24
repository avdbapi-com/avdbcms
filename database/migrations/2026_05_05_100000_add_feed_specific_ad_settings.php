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

        $this->put('ad_grid_layout', 'grid_card', false);

        foreach ($this->sampleAds() as $key => $value) {
            $this->put($key, $value, false);
        }

        Setting::clearCache(array_merge(['ad_grid_layout'], array_keys($this->sampleAds())), ['monetization']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->where('group', 'monetization')
            ->where('key', 'ad_grid_layout')
            ->where('value', 'grid_card')
            ->delete();

        foreach ($this->sampleAds() as $key => $value) {
            DB::table('settings')
                ->where('group', 'monetization')
                ->where('key', $key)
                ->whereIn('value', [$value, $this->legacyCode($value)])
                ->delete();
        }

        Setting::clearCache(array_merge(['ad_grid_layout'], array_keys($this->sampleAds())), ['monetization']);
    }

    /**
     * The same sample as sites installed before 1.2.1 stored it: their demo ads carry the old class name
     * avd-test-ad (resources/sass/app.scss styles both names).
     */
    private function legacyCode(string $code): string
    {
        return str_replace('avd-demo-ad', 'avd-test-ad', $code);
    }

    private function put(string $key, string $value, bool $overwrite = false): void
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
            'ad_code_grid_smart_discovery' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--smart" href="/search" rel="sponsored" aria-label="Sponsored discovery ad"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>Discovery boost</strong><small>Promote tags, collections, and search campaigns inside Smart Discovery.</small></span><span class="avd-demo-ad__cta">Explore</span></a>
HTML,
            'ad_code_grid_creator_spotlight' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--creator" href="/premium" rel="sponsored" aria-label="Sponsored creator spotlight ad"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>Creator Spotlight placement</strong><small>A native slot for creator, channel, and upload campaigns.</small></span><span class="avd-demo-ad__cta">Promote</span></a>
HTML,
            'ad_code_grid_trending_actors' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--trending" href="/trend" rel="sponsored" aria-label="Sponsored trending actors ad"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>Trending reach</strong><small>Match actor and collection discovery with a responsive native placement.</small></span><span class="avd-demo-ad__cta">View</span></a>
HTML,
            'ad_code_grid_new_videos' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--new" href="/premium" rel="sponsored" aria-label="Sponsored new videos ad"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>New Videos sponsor</strong><small>High-visibility native inventory inside the freshest uploads feed.</small></span><span class="avd-demo-ad__cta">Open</span></a>
HTML,
        ];
    }
};
