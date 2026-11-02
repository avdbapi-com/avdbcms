<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Upload18 upload (its did) of a member, kept for good: the member who claimed it first owns it, and while Upload18
 * is still processing the file, the row is the member's pending draft that the site finishes by itself
 * (App\Support\Upload18\UploadClaims, the scheduler's upload18:finish-pending).
 *
 * state: pending (Upload18 is still processing; video_id is the private draft), done (the draft has its embed code),
 * failed (Upload18 never finished it, or the video Upload18 returned is not the member's file: the draft never got an
 * embed code). verified_at: Upload18 listed the item and it matched the file the member's browser sent.
 */
class Upload18Claim extends Model
{
    public const PENDING = 'pending';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $table = 'upload18_claims';

    protected $guarded = ['id'];

    protected $casts = [
        'user_id' => 'integer',
        'video_id' => 'integer',
        'file_size' => 'integer',
        'file_duration' => 'float',
        'attempts' => 'integer',
        'needs_thumbnails' => 'boolean',
        'verified_at' => 'datetime',
        'next_check_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class)->withTrashed();
    }

    public function isPending(): bool
    {
        return $this->state === self::PENDING;
    }
}
