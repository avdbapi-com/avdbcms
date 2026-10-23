<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoViewDailyStat extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'view_date' => 'date',
        'views_count' => 'integer',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
