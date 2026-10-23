<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('videos')) {
            Schema::table('videos', function (Blueprint $table) {
                if (! Schema::hasColumn('videos', 'xvid_id')) {
                    $table->unsignedInteger('xvid_id')->nullable()->unique()->after('avdb_content_hash');
                }

                if (! Schema::hasColumn('videos', 'xvid_code')) {
                    $table->string('xvid_code', 100)->nullable()->index()->after('xvid_id');
                }

                if (! Schema::hasColumn('videos', 'xvid_quality')) {
                    $table->string('xvid_quality', 50)->nullable()->after('xvid_code');
                }

                if (! Schema::hasColumn('videos', 'xvid_actor')) {
                    $table->string('xvid_actor', 500)->nullable()->after('xvid_quality');
                }

                if (! Schema::hasColumn('videos', 'xvid_author')) {
                    $table->string('xvid_author', 255)->nullable()->after('xvid_actor');
                }

                if (! Schema::hasColumn('videos', 'xvid_release_date')) {
                    $table->date('xvid_release_date')->nullable()->index()->after('xvid_author');
                }

                if (! Schema::hasColumn('videos', 'xvid_updated_at')) {
                    $table->timestamp('xvid_updated_at')->nullable()->after('xvid_release_date');
                }

                if (! Schema::hasColumn('videos', 'xvid_content_hash')) {
                    $table->string('xvid_content_hash', 64)->nullable()->after('xvid_updated_at');
                }

                if (! Schema::hasColumn('videos', 'provider')) {
                    $table->string('provider', 50)->nullable()->index()->after('xvid_content_hash');
                }
            });

            // Backfill provider for existing AVDB videos
            try {
                DB::table('videos')
                    ->whereNotNull('avdb_id')
                    ->whereNull('provider')
                    ->update(['provider' => 'avdb']);
            } catch (\Throwable $e) {
                // Ignore if any DB issue occurs during migration backfill
            }
        }

        if (! Schema::hasTable('xvid_import_runs')) {
            Schema::create('xvid_import_runs', function (Blueprint $table) {
                $table->id();
                $table->string('status', 20)->default('queued')->index();
                $table->json('settings')->nullable();
                $table->unsignedInteger('imported')->default(0);
                $table->unsignedInteger('skipped')->default(0);
                $table->unsignedInteger('updated')->default(0);
                $table->unsignedInteger('failed')->default(0);
                $table->unsignedInteger('tags_created')->default(0);
                $table->unsignedInteger('thumbs_downloaded')->default(0);
                $table->unsignedInteger('total_pages')->default(0);
                $table->unsignedInteger('completed_pages')->default(0);
                $table->unsignedTinyInteger('progress')->default(0);
                $table->string('current_category', 100)->nullable();
                $table->unsignedInteger('current_page')->nullable();
                $table->string('message', 500)->nullable();
                $table->longText('log')->nullable();
                $table->boolean('cancel_requested')->default(false)->index();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('xvid_import_schedules')) {
            Schema::create('xvid_import_schedules', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('expression', 100)->default('0 3 * * *');
                $table->boolean('is_active')->default(true)->index();
                $table->json('categories')->nullable();
                $table->unsignedInteger('pages')->default(1);
                $table->unsignedInteger('start_page')->default(1);
                $table->unsignedInteger('pagesize')->default(50);
                $table->unsignedInteger('delay')->default(0);
                $table->json('settings')->nullable();
                $table->timestamp('last_run_at')->nullable();
                $table->timestamp('next_run_at')->nullable();
                $table->foreignId('last_run_id')->nullable()->constrained('xvid_import_runs')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('xvid_import_schedules');
        Schema::dropIfExists('xvid_import_runs');

        if (Schema::hasTable('videos')) {
            Schema::table('videos', function (Blueprint $table) {
                $columns = [
                    'provider',
                    'xvid_content_hash',
                    'xvid_updated_at',
                    'xvid_release_date',
                    'xvid_author',
                    'xvid_actor',
                    'xvid_quality',
                    'xvid_code',
                    'xvid_id',
                ];

                foreach ($columns as $col) {
                    if (Schema::hasColumn('videos', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
