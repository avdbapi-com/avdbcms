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

        $verification = DB::table('settings')->where('key', 'email_verification_required')->first();

        if ($verification) {
            DB::table('settings')
                ->where('key', 'email_verification_required')
                ->update([
                    'group' => 'users',
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('settings')->insert([
                'key' => 'email_verification_required',
                'value' => '1',
                'group' => 'users',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($this->emailSettings() as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => (string) $value,
                    'group' => 'email',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Setting::clearCache(
            array_merge(['email_verification_required'], array_keys($this->emailSettings())),
            ['users', 'email']
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->whereIn('key', array_merge(['email_verification_required'], array_keys($this->emailSettings())))
            ->delete();

        Setting::clearCache(
            array_merge(['email_verification_required'], array_keys($this->emailSettings())),
            ['users', 'email']
        );
    }

    private function emailSettings(): array
    {
        return [
            'mail_mailer' => env('MAIL_MAILER', 'smtp'),
            'support_email' => env('SUPPORT_EMAIL', ''),
            'server_email' => env('SERVER_EMAIL', ''),
        ];
    }
};
