<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (!Schema::hasColumn('plans', 'paypal_plan_id')) {
                $table->string('paypal_plan_id')->nullable()->after('stripe_id')->index();
            }
        });

        Schema::table('premiums', function (Blueprint $table) {
            if (!Schema::hasColumn('premiums', 'payment_provider')) {
                $table->string('payment_provider', 32)->default('stripe')->after('id')->index();
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'payment_provider')) {
                $table->string('payment_provider', 32)->default('stripe')->after('id')->index();
            }
        });

        if (Schema::hasTable('settings')) {
            foreach ([
                ['key' => 'stripe_enabled', 'value' => '1', 'group' => 'monetization'],
                ['key' => 'paypal_enabled', 'value' => '1', 'group' => 'monetization'],
            ] as $setting) {
                DB::table('settings')->updateOrInsert(
                    ['key' => $setting['key']],
                    $setting + ['updated_at' => now(), 'created_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'payment_provider')) {
                $table->dropIndex(['payment_provider']);
                $table->dropColumn('payment_provider');
            }
        });

        Schema::table('premiums', function (Blueprint $table) {
            if (Schema::hasColumn('premiums', 'payment_provider')) {
                $table->dropIndex(['payment_provider']);
                $table->dropColumn('payment_provider');
            }
        });

        Schema::table('plans', function (Blueprint $table) {
            if (Schema::hasColumn('plans', 'paypal_plan_id')) {
                $table->dropIndex(['paypal_plan_id']);
                $table->dropColumn('paypal_plan_id');
            }
        });

        if (Schema::hasTable('settings')) {
            DB::table('settings')
                ->whereIn('key', ['stripe_enabled', 'paypal_enabled'])
                ->delete();
        }
    }
};
