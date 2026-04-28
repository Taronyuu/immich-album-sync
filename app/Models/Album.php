<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'name',
    'schedule',
    'source_type',
    'source_base_url',
    'source_share_key',
    'source_share_password',
    'source_api_key',
    'source_album_id',
    'target_album_name',
    'target_album_id',
    'on_remote_delete',
    'is_active',
    'last_synced_at',
    'last_status',
    'last_error',
])]
class Album extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'source_share_key' => 'encrypted',
            'source_share_password' => 'encrypted',
            'source_api_key' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(Mapping::class);
    }

    public function jobRuns(): HasMany
    {
        return $this->hasMany(JobRun::class);
    }

    public function latestJobRun(): HasOne
    {
        return $this->hasOne(JobRun::class)->latestOfMany();
    }
}
