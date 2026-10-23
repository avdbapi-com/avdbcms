<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')
            ->where('name', 'annually')
            ->where('duration', 7)
            ->update(['duration' => 12]);
    }

    public function down(): void
    {
        //
    }
};
