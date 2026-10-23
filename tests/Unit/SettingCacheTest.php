<?php

namespace Tests\Unit;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SettingCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_clear_cache_forgets_supplied_deleted_keys_and_groups(): void
    {
        Setting::query()->create([
            'key' => 'temporary_setting',
            'value' => 'old',
            'group' => 'temporary_group',
        ]);

        Cache::forever('setting.temporary_setting', 'old');
        Cache::forever('settings.temporary_group', ['temporary_setting' => 'old']);
        Cache::forever('settings.__all_grouped', ['temporary_group' => ['temporary_setting' => 'old']]);

        Setting::query()->where('key', 'temporary_setting')->delete();
        Setting::clearCache(['temporary_setting'], ['temporary_group']);

        $this->assertFalse(Cache::has('setting.temporary_setting'));
        $this->assertFalse(Cache::has('settings.temporary_group'));
        $this->assertFalse(Cache::has('settings.__all_grouped'));
    }
}
