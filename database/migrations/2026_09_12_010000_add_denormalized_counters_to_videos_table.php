<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_COMMENTS = 'videos_comments_count_published_id_idx';

    public function up(): void
    {
        if (! Schema::hasTable('videos')) {
            return;
        }

        Schema::table('videos', function (Blueprint $table): void {
            if (! Schema::hasColumn('videos', 'likes_count')) {
                $table->unsignedBigInteger('likes_count')->default(0)->after('views');
            }
            if (! Schema::hasColumn('videos', 'dislikes_count')) {
                $table->unsignedBigInteger('dislikes_count')->default(0)->after('likes_count');
            }
            if (! Schema::hasColumn('videos', 'comments_count')) {
                $table->unsignedBigInteger('comments_count')->default(0)->after('dislikes_count');
            }
        });

        if (! Schema::hasIndex('videos', self::INDEX_COMMENTS)) {
            Schema::table('videos', function (Blueprint $table): void {
                $table->index(['comments_count', 'published_at', 'id'], self::INDEX_COMMENTS);
            });
        }

        // Backfill counters from existing interactions & comments
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            try {
                if (Schema::hasTable('interactions')) {
                    DB::statement("
                        UPDATE videos v
                        LEFT JOIN (
                            SELECT likeable_id,
                                   SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as cnt_likes,
                                   SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as cnt_dislikes
                            FROM interactions
                            WHERE likeable_type IN ('App\\\\Models\\\\Video', 'video')
                            GROUP BY likeable_id
                        ) i ON i.likeable_id = v.id
                        SET v.likes_count = COALESCE(i.cnt_likes, 0),
                            v.dislikes_count = COALESCE(i.cnt_dislikes, 0)
                    ");
                }

                if (Schema::hasTable('comments')) {
                    DB::statement("
                        UPDATE videos v
                        LEFT JOIN (
                            SELECT video_id, COUNT(*) as cnt_comments
                            FROM comments
                            GROUP BY video_id
                        ) c ON c.video_id = v.id
                        SET v.comments_count = COALESCE(c.cnt_comments, 0)
                    ");
                }
            } catch (\Throwable) {
                // Ignore if tables are empty or lock unavailable
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('videos')) {
            return;
        }

        Schema::table('videos', function (Blueprint $table): void {
            if (Schema::hasIndex('videos', self::INDEX_COMMENTS)) {
                $table->dropIndex(self::INDEX_COMMENTS);
            }

            $columnsToDrop = [];
            foreach (['comments_count', 'dislikes_count', 'likes_count'] as $col) {
                if (Schema::hasColumn('videos', $col)) {
                    $columnsToDrop[] = $col;
                }
            }

            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
