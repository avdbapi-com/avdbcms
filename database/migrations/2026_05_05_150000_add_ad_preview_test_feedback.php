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

        $current = (string) DB::table('settings')
            ->where('group', 'monetization')
            ->where('key', 'ad_code_onclick')
            ->value('value');

        if (trim($current) !== '' && ! str_contains($current, 'AVDBCMS OnClick / Popunder sample fired from a user click.') && ! str_contains($current, 'AVDBClip OnClick / Popunder sample fired from a user click.')) {
            return;
        }

        $query = DB::table('settings')
            ->where('group', 'monetization')
            ->where('key', 'ad_code_onclick');

        if ($query->exists()) {
            $query->update([
                'value' => $this->previewOnclickSample(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('settings')->insert([
                'group' => 'monetization',
                'key' => 'ad_code_onclick',
                'value' => $this->previewOnclickSample(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Setting::clearCache(['ad_code_onclick'], ['monetization']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $current = (string) DB::table('settings')
            ->where('group', 'monetization')
            ->where('key', 'ad_code_onclick')
            ->value('value');

        if (! str_contains($current, 'OnClick / Popunder sample fired. Paste a real network tag')) {
            return;
        }

        DB::table('settings')
            ->where('group', 'monetization')
            ->where('key', 'ad_code_onclick')
            ->update([
                'value' => $this->legacyOnclickSample(),
                'updated_at' => now(),
            ]);

        Setting::clearCache(['ad_code_onclick'], ['monetization']);
    }

    private function previewOnclickSample(): string
    {
        return <<<'HTML'
<script>
(function () {
  if (window.avdOnclickAdSampleInstalled) return;
  window.avdOnclickAdSampleInstalled = true;

  function showToast(message) {
    if (!document.body) return;

    var toast = document.createElement('div');
    toast.className = 'avd-ad-preview-toast';
    toast.setAttribute('role', 'status');
    toast.textContent = message;
    document.body.appendChild(toast);

    window.requestAnimationFrame(function () {
      toast.classList.add('is-visible');
    });

    window.setTimeout(function () {
      toast.classList.remove('is-visible');
      window.setTimeout(function () {
        toast.remove();
      }, 220);
    }, 3200);
  }

  document.addEventListener('click', function (event) {
    var target = event.target;

    if (target && target.closest && target.closest('a, button, input, textarea, select, label, [data-bs-toggle]')) return;
    if (sessionStorage.getItem('avd-onclick-ad-sample-fired')) return;

    sessionStorage.setItem('avd-onclick-ad-sample-fired', '1');
    showToast('OnClick / Popunder sample fired. Paste a real network tag to open real popunders.');
    console.info('AVDBCMS OnClick / Popunder sample fired from a user click.');
  }, { capture: true });
})();
</script>
HTML;
    }

    private function legacyOnclickSample(): string
    {
        return <<<'HTML'
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
HTML;
    }
};
