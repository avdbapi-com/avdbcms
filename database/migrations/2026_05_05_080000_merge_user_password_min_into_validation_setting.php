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

        $legacy = DB::table('settings')
            ->where('group', 'users')
            ->where('key', 'password_min_length')
            ->value('value');

        if ($legacy !== null) {
            $validation = DB::table('settings')
                ->where('key', 'user_password_min')
                ->first();

            $legacyValue = (string) max(6, min(128, (int) $legacy));
            $defaultValue = (string) config('validation.user.password.min', 6);

            if (! $validation) {
                DB::table('settings')->insert([
                    'key' => 'user_password_min',
                    'value' => $legacyValue,
                    'group' => 'validation',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ((string) $validation->value === $defaultValue) {
                DB::table('settings')
                    ->where('key', 'user_password_min')
                    ->update([
                        'value' => $legacyValue,
                        'group' => 'validation',
                        'updated_at' => now(),
                    ]);
            }
        }

        DB::table('settings')
            ->where('group', 'users')
            ->where('key', 'password_min_length')
            ->delete();

        Setting::clearCache(['password_min_length', 'user_password_min'], ['users', 'validation']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        if (! DB::table('settings')->where('key', 'password_min_length')->exists()) {
            $validationValue = DB::table('settings')->where('key', 'user_password_min')->value('value');

            DB::table('settings')->insert([
                'key' => 'password_min_length',
                'value' => $validationValue !== null ? (string) $validationValue : '8',
                'group' => 'users',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Setting::clearCache(['password_min_length', 'user_password_min'], ['users', 'validation']);
    }
};
