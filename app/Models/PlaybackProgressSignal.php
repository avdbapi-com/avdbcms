<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybackProgressSignal extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'position_seconds' => 'integer',
        'duration_seconds' => 'integer',
        'progress_basis_points' => 'integer',
        'last_played_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
