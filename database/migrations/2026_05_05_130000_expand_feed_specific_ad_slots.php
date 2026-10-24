<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $layoutDefaults = [
        'ad_grid_layout_trend_page' => 'inherit',
        'ad_grid_layout_category_page' => 'inherit',
        'ad_grid_layout_subscriptions' => 'inherit',
        'ad_grid_layout_channel_videos' => 'inherit',
        'ad_grid_layout_search_results' => 'wide_row',
        'ad_grid_layout_playlist_videos' => 'wide_row',
        'ad_grid_layout_watch_related' => 'wide_row',
        'ad_grid_layout_channel_home_featured' => 'inherit',
        'ad_grid_layout_channel_home_latest' => 'inherit',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach ($this->layoutDefaults as $key => $value) {
            $this->put($key, $value, false);
        }

        foreach ($this->sampleAds() as $key => $value) {
            $this->put($key, $value, false);
        }

        DB::table('settings')
            ->where('group', 'monetization')
            ->where('key', 'ad_code_grid')
            ->delete();

        Setting::clearCache(array_merge(array_keys($this->layoutDefaults), array_keys($this->sampleAds()), ['ad_code_grid']), ['monetization']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $this->put('ad_code_grid', <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--native" href="/premium" rel="sponsored" aria-label="Sponsored creator promotion"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>Creator-ready promotion</strong><small>Native card slot sized for every feed layout.</small></span><span class="avd-demo-ad__cta">Open</span></a>
HTML);

        DB::table('settings')
            ->where('group', 'monetization')
            ->whereIn('key', array_merge(array_keys($this->layoutDefaults), array_keys($this->sampleAds())))
            ->delete();

        Setting::clearCache(array_merge(array_keys($this->layoutDefaults), array_keys($this->sampleAds()), ['ad_code_grid']), ['monetization']);
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
     * Demo ad cards for a new site (class avd-demo-ad, styled in resources/sass/app.scss; sites installed before
     * 1.2.1 keep their stored codes with the old name avd-test-ad, which the stylesheet still styles). They only
     * fill empty ad slots, so a site's own codes are never replaced.
     */
    private function sampleAds(): array
    {
        return [
            'ad_code_grid_trend_page' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--trend" href="/trend" rel="sponsored" aria-label="Sponsored trending page ad"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>Trending page sponsor</strong><small>Responsive native inventory for viewers browsing popular videos.</small></span><span class="avd-demo-ad__cta">View</span></a>
HTML,
            'ad_code_grid_category_page' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--category" href="/categories" rel="sponsored" aria-label="Sponsored category page ad"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>Category placement</strong><small>Target campaigns by category without breaking the video grid.</small></span><span class="avd-demo-ad__cta">Browse</span></a>
HTML,
            'ad_code_grid_subscriptions' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--subs" href="/premium" rel="sponsored" aria-label="Sponsored subscriptions ad"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>Subscriber feed sponsor</strong><small>Reach signed-in viewers while they check fresh channel uploads.</small></span><span class="avd-demo-ad__cta">Open</span></a>
HTML,
            'ad_code_grid_channel_videos' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--channel" href="/premium" rel="sponsored" aria-label="Sponsored channel videos ad"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>Channel video placement</strong><small>Native inventory for public creator and channel video tabs.</small></span><span class="avd-demo-ad__cta">Promote</span></a>
HTML,
            'ad_code_grid_search_results' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--search" href="/premium" rel="sponsored" aria-label="Sponsored search results ad"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>Search result placement</strong><small>Native inventory inside intent-heavy search result lists.</small></span><span class="avd-demo-ad__cta">Open</span></a>
HTML,
            'ad_code_grid_playlist_videos' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--playlist" href="/premium" rel="sponsored" aria-label="Sponsored playlist videos ad"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>Playlist video sponsor</strong><small>Reach viewers while they browse curated video lists.</small></span><span class="avd-demo-ad__cta">View</span></a>
HTML,
            'ad_code_grid_watch_related' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--related" href="/premium" rel="sponsored" aria-label="Sponsored related videos ad"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>Related videos placement</strong><small>Compact native slot for the watch-page discovery column.</small></span><span class="avd-demo-ad__cta">Open</span></a>
HTML,
            'ad_code_grid_channel_home_featured' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--featured" href="/premium" rel="sponsored" aria-label="Sponsored channel featured ad"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>Channel featured sponsor</strong><small>Native slot near featured creator videos.</small></span><span class="avd-demo-ad__cta">Promote</span></a>
HTML,
            'ad_code_grid_channel_home_latest' => <<<'HTML'
<a class="avd-demo-ad avd-demo-ad--latest" href="/premium" rel="sponsored" aria-label="Sponsored channel latest ad"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>Latest uploads sponsor</strong><small>Native slot near new uploads on public channel pages.</small></span><span class="avd-demo-ad__cta">Open</span></a>
HTML,
        ];
    }
};
