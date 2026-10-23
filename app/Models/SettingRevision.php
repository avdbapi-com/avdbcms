<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One settings change (table admin_setting_revisions): before/after are a group's complete stored rows (key => value)
 * or the keys a save wrote nested by group (meta.scope 'group' / 'keys'). Read and written through
 * App\Support\SettingsRevision, which guards the missing table.
 */
class SettingRevision extends Model
{
    protected $table = 'admin_setting_revisions';

    protected $guarded = ['id'];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'changed' => 'array',
        'meta' => 'array',
        'user_id' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
