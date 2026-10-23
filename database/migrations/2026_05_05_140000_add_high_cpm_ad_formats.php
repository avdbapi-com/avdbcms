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

        foreach ($this->settings() as $key => $value) {
            $this->put($key, $value, false);
        }

        Setting::clearCache(array_keys($this->settings()), ['monetization']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->where('group', 'monetization')
            ->whereIn('key', array_keys($this->settings()))
            ->delete();

        Setting::clearCache(array_keys($this->settings()), ['monetization']);
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

    private function settings(): array
    {
        return [
            'ad_onclick_enabled' => '1',
            'ad_code_onclick' => <<<'HTML'
<script>
(function () {
  if (window.avdOnclickAdSampleInstalled) return;
  window.avdOnclickAdSampleInstalled = true;
  document.addEventListener('click', function (event) {
    if (event.target.closest('a, button, input, textarea, select, [data-bs-toggle]')) return;
    if (sessionStorage.getItem('avd-onclick-ad-sample-fired')) return;
    sessionStorage.setItem('avd-onclick-ad-sample-fired', '1');
    console.info('AVDBCMS OnClick / Popunder sample fired from a user click.');
  }, { capture: true });
})();
</script>
HTML,
        ];
    }
};
