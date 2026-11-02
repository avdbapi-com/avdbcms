<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Never lose a video" (1.2.3): one row per Upload18 upload (its did) of a member, kept for good
 * (App\Models\Upload18Claim, App\Support\Upload18\UploadClaims).
 *
 *  - A did belongs to the first member who claimed it, permanently. Up to 1.2.2 that link lived only in the cache for
 *    30 minutes, after which another member could register the same Upload18 video as their own (security plan S09).
 *  - While Upload18 is still processing the file, the row is the member's pending draft: the scheduler (and the draft's
 *    own page) finish it when Upload18 is ready, also when the member closed the upload page at 92 %.
 *
 * Member uploads finished before 1.2.3 (videos.upload18_vid; imported videos have none) get their row too, so they stay
 * their uploader's. Safe to run again (the table and each row are only added when missing) and on 1.2.0, 1.2.1 and 1.2.2
 * databases.
 */
return new class extends Migration
{
    private const TABLE = 'upload18_claims';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) {
                $table->id();
                $table->string('did', 120)->unique();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('video_id')->nullable()->index();
                $table->string('vid', 160)->nullable();
                $table->string('state', 16)->default('pending');
                $table->string('file_name', 255)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->decimal('file_duration', 12, 3)->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('next_check_at')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->string('error', 40)->nullable();
                $table->boolean('needs_thumbnails')->default(false);
                $table->timestamps();

                // The scheduler's question every minute: is a pending draft due, or a finished one without thumbnails?
                $table->index(['state', 'next_check_at'], 'upload18_claims_state_next_idx');
                $table->index(['needs_thumbnails', 'id'], 'upload18_claims_thumbs_idx');
            });
        }

        $this->backfill();
    }

    /**
     * Member uploads made before this table: the first video row of each did (the lowest id, soft-deleted ones too:
     * the did stays theirs) becomes a finished claim. Keyset pages of 500, so a large videos table is read once.
     */
    private function backfill(): void
    {
        if (! Schema::hasTable('videos') || ! Schema::hasColumn('videos', 'upload18_vid') || ! Schema::hasColumn('videos', 'user_id')) {
            return;
        }

        $first = DB::table('videos')
            ->selectRaw('MIN(id) as first_id')
            ->whereNotNull('upload18_vid')
            ->where('upload18_vid', '<>', '')
            ->whereNotNull('user_id')
            ->groupBy('upload18_vid');

        $now = now();

        DB::table('videos')
            ->joinSub($first, 'firsts', 'firsts.first_id', '=', 'videos.id')
            ->leftJoin(self::TABLE, self::TABLE.'.did', '=', 'videos.upload18_vid')
            ->whereNull(self::TABLE.'.id')
            ->select([
                'videos.id', 'videos.upload18_vid', 'videos.user_id', 'videos.embed_code', 'videos.original_file_name',
                'videos.size', 'videos.created_at', 'videos.uploaded_at',
            ])
            ->chunkById(500, function ($rows) use ($now): void {
                $insert = [];

                foreach ($rows as $row) {
                    $did = trim((string) $row->upload18_vid);

                    if ($did === '' || strlen($did) > 120) {
                        continue;
                    }

                    $vid = trim((string) ($row->embed_code ?? ''));
                    $insert[] = [
                        'did' => $did,
                        'user_id' => (int) $row->user_id,
                        'video_id' => (int) $row->id,
                        // An embed code that is a whole player address (old imports) is no Upload18 vid.
                        'vid' => $vid !== '' && strlen($vid) <= 160 && preg_match('/^[A-Za-z0-9_-]+$/', $vid) === 1 ? $vid : null,
                        'state' => 'done',
                        'file_name' => $row->original_file_name !== null ? mb_substr((string) $row->original_file_name, 0, 255) : null,
                        'file_size' => is_numeric($row->size) && (int) $row->size > 0 ? (int) $row->size : null,
                        'verified_at' => $row->created_at ?? $now,
                        'finished_at' => $row->uploaded_at ?? $row->created_at ?? $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($insert !== []) {
                    DB::table(self::TABLE)->insertOrIgnore($insert);
                }
            }, 'videos.id', 'id');
    }

    public function down(): void
    {
        // Nothing: the rows are what keeps each member's Upload18 videos theirs. Dropping them on a rollback would let
        // another member register those videos again.
    }
};
