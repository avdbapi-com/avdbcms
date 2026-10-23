<?php

use App\Models\Language;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('languages')) {
            return;
        }

        // The original seed omitted Arabic's RTL flag. Only repair its unchanged
        // seed metadata; recorded admin edits and all other languages stay intact.
        $eligible = DB::table('languages')
            ->where('code', 'ar')
            ->where('direction', 'ltr')
            ->where('label', 'العربية')
            ->where('emoji', '🇸🇦')
            ->where('sort_order', 12)
            ->where('is_active', true)
            ->where('is_default', false)
            ->whereNotNull('created_at')
            ->whereNotNull('updated_at')
            ->whereColumn('created_at', 'updated_at');

        $changed = 0;
        foreach ((clone $eligible)->get(['id', 'code', 'label', 'emoji']) as $language) {
            // Some production collations ignore case/diacritics. Preserve any
            // customization that SQL equality would otherwise consider equal.
            if ($language->code !== 'ar' || $language->label !== 'العربية' || $language->emoji !== '🇸🇦') {
                continue;
            }

            // Query builder deliberately preserves both original timestamps.
            $changed += (clone $eligible)->where('id', $language->id)->update(['direction' => 'rtl']);
        }

        if ($changed > 0) {
            Language::flushCache();
        }
    }

    public function down(): void
    {
        // Data correction is intentionally retained: a rollback cannot establish
        // whether a current RTL value is an administrator's subsequent choice.
    }
};
