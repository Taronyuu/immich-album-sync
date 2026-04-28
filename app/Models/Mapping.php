<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'album_id',
    'remote_id',
    'remote_checksum',
    'local_asset_id',
    'imported_at',
])]
class Mapping extends Model
{
    protected $primaryKey = null;
    public $incrementing = false;
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
        ];
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }
}
