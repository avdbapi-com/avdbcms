<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();          // 'en', 'vi', 'fr' …
            $table->string('label', 100);                  // 'English', 'Tiếng Việt' …
            $table->string('emoji', 20)->default('🌐');    // flag emoji
            $table->string('direction', 3)->default('ltr'); // 'ltr' or 'rtl'
            $table->boolean('is_default')->default(false); // only one should be true
            $table->boolean('is_active')->default(true);   // hide without deleting
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed from config/languages.php so nothing is lost
        $defaultLocale = config('app.locale', 'en');
        $languages     = config('languages', []);
        $i = 0;
        foreach ($languages as $code => $info) {
            DB::table('languages')->insert([
                'code'       => $code,
                'label'      => $info['label'] ?? $code,
                'emoji'      => $info['emoji'] ?? '🌐',
                'direction'  => ($info['rtl'] ?? false) ? 'rtl' : 'ltr',
                'is_default' => $code === $defaultLocale,
                'is_active'  => true,
                'sort_order' => $i++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
