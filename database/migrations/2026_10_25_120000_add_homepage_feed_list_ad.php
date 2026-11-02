<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Money > Ads gets a list ad for the homepage feed: the main video list of the homepage layouts with a top banner and
 * one feed (Spotlight, the layout of a new site, and Cinema rows, Mosaic, Shorts and Control room). Up to 1.2.2 that
 * list had no ad code of its own and never showed a list ad (App\Support\ListAdContexts).
 *
 * Zero-config: a site whose Classic grid homepage list ("New videos", ad_code_grid_new_videos) has an ad code gets the
 * same code and shape for the homepage feed, so the ad it already has shows there too. Only into an empty homepage feed
 * code that nobody changed: a missing row, or an empty one that was never changed after it was written (a settings page
 * saved before "Finish update"). An owner's own code stays, and so does a code an owner cleared (its row changed after
 * it was written), also when this runs again. A site without a New videos code gets the empty field. New sites: the
 * same rule right after the bundled database is imported (its ad codes are empty); a site built from the migrations
 * alone gets the demo ad of New videos. Tools > Logs > Admin actions records a copy of the site's own ad code (the
 * bundled demo card is no news for the owner).
 */
return new class extends Migration
{
    public const CODE = 'ad_code_grid_home_feed';

    public const SHAPE = 'ad_grid_layout_home_feed';

    public const FROM_CODE = 'ad_code_grid_new_videos';

    public const FROM_SHAPE = 'ad_grid_layout_new_videos';

    /** The values a list's shape may have ('inherit' = the main shape of Money > Ads). */
    public const SHAPES = ['inherit', 'grid_card', 'wide_row'];

    public const AUDIT_ACTION = 'update.homepage_feed_list_ad';

    public const AUDIT_DETAILS = 'Money > Ads: the homepage feed got the ad code and shape of the Classic grid homepage list (New videos)';

    /**
     * The demo card the migrations give New videos (2026_05_05_100000); sites installed before 1.2.1 store it with the
     * class name avd-test-ad. Copying it unchanged is not written to Admin actions.
     */
    public const DEMO_CODE = '<a class="avd-demo-ad avd-demo-ad--new" href="/premium" rel="sponsored" aria-label="Sponsored new videos ad"><span class="avd-demo-ad__label">Ad</span><span class="avd-demo-ad__copy"><strong>New Videos sponsor</strong><small>High-visibility native inventory inside the freshest uploads feed.</small></span><span class="avd-demo-ad__cta">Open</span></a>';

    private const GROUP = 'monetization';

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $rows = DB::table('settings')
            ->whereIn('key', [self::CODE, self::SHAPE, self::FROM_CODE, self::FROM_SHAPE])
            ->orderBy('id')
            ->get(['id', 'key', 'value', 'created_at', 'updated_at'])
            // The first row of a key, as Setting::get() reads it; some collations match keys loosely, so compare exactly.
            ->filter(fn (object $row): bool => in_array((string) $row->key, [self::CODE, self::SHAPE, self::FROM_CODE, self::FROM_SHAPE], true))
            ->unique('key')
            ->keyBy('key');

        $code = $rows->get(self::CODE);
        $shape = $rows->get(self::SHAPE);
        $fromCode = $rows->has(self::FROM_CODE) ? (string) $rows->get(self::FROM_CODE)->value : '';
        $fromShape = $rows->has(self::FROM_SHAPE) ? (string) $rows->get(self::FROM_SHAPE)->value : 'inherit';
        $copy = trim($fromCode) !== '' && ($code === null || (trim((string) $code->value) === '' && $this->unchanged($code)));
        $written = [];

        if ($copy) {
            $this->write(self::CODE, $fromCode, $code);
            $written[] = self::CODE;

            // The shape follows the code, unless the homepage feed already has a shape somebody chose: one changed after
            // it was written, or a card or full-width row (this migration only ever writes "Same as above" alone).
            if ($shape === null || ($this->unchanged($shape) && ! in_array((string) $shape->value, ['grid_card', 'wide_row'], true))) {
                $this->write(self::SHAPE, in_array($fromShape, self::SHAPES, true) ? $fromShape : 'inherit', $shape);
                $written[] = self::SHAPE;
            }
        } else {
            if ($code === null) {
                $this->write(self::CODE, '', null);
                $written[] = self::CODE;
            }

            if ($shape === null) {
                $this->write(self::SHAPE, 'inherit', null);
                $written[] = self::SHAPE;
            }
        }

        if ($written === []) {
            return;
        }

        // The site reads these rows from the settings cache on every request.
        Setting::clearCache($written, [self::GROUP]);

        if (! $copy || self::isDemoCode($fromCode)) {
            return;
        }

        // Tools > Logs > Admin actions is the owner's record of what changed: a new ad shows on the homepage feed.
        try {
            if (Schema::hasTable('admin_audit_logs')) {
                DB::table('admin_audit_logs')->insert([
                    'user_id' => null,
                    'action' => self::AUDIT_ACTION,
                    'details' => self::AUDIT_DETAILS,
                    'ip_address' => null,
                    'user_agent' => null,
                    'created_at' => now(),
                ]);
            }

            Log::info('AVDBCMS: the homepage feed got the list ad code and shape of the Classic grid homepage list (New videos).');
        } catch (Throwable) {
            // The log entries are a courtesy; the copy itself is done.
        }
    }

    public function down(): void
    {
        // Nothing to undo: the homepage feed may hold the owner's own code by now, and older versions never read it.
    }

    /** Whether a code is the bundled demo card of New videos, unchanged (either class name, any line endings). */
    public static function isDemoCode(string $code): bool
    {
        $code = trim(str_replace("\r\n", "\n", $code));

        return $code === self::DEMO_CODE || $code === str_replace('avd-demo-ad', 'avd-test-ad', self::DEMO_CODE);
    }

    /** Whether nobody changed a settings row after it was written (Setting::set() updates updated_at on a change). */
    private function unchanged(object $row): bool
    {
        return (string) ($row->created_at ?? '') === (string) ($row->updated_at ?? '');
    }

    private function write(string $key, string $value, ?object $row): void
    {
        if ($row !== null) {
            DB::table('settings')->where('id', $row->id)->update([
                'value' => $value,
                'group' => self::GROUP,
                'updated_at' => now(),
            ]);

            return;
        }

        $now = now();

        DB::table('settings')->insert([
            'key' => $key,
            'value' => $value,
            'group' => self::GROUP,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
