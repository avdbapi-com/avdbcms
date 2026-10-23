<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UID_LENGTH = 8;

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'profile_uid')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->char('profile_uid', self::UID_LENGTH)->nullable()->unique()->after('slug');
            });
        }

        $reserved = DB::table('users')
            ->whereNotNull('profile_uid')
            ->pluck('profile_uid')
            ->map(fn ($uid) => strtolower((string) $uid))
            ->all();

        $reserved = array_fill_keys($reserved, true);

        DB::table('users')
            ->whereNull('profile_uid')
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(500, function ($users) use (&$reserved): void {
                foreach ($users as $user) {
                    $uid = $this->makeUid($reserved);
                    $reserved[$uid] = true;

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['profile_uid' => $uid]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'profile_uid')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_profile_uid_unique');
            $table->dropColumn('profile_uid');
        });
    }

    private function makeUid(array $reserved): string
    {
        do {
            $uid = $this->randomBase36(self::UID_LENGTH);
        } while (isset($reserved[$uid]));

        return $uid;
    }

    private function randomBase36(int $length): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
        $value = '';

        for ($i = 0; $i < $length; $i++) {
            $value .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $value;
    }
};
