<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'album_id',
    'status',
    'trigger',
    'started_at',
    'finished_at',
    'uploaded_count',
    'deduped_count',
    'removed_count',
    'failed_count',
    'error_message',
    'log',
])]
class JobRun extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    public const TRIGGER_SCHEDULED = 'scheduled';
    public const TRIGGER_MANUAL = 'manual';

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'uploaded_count' => 'integer',
            'deduped_count' => 'integer',
            'removed_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    protected function durationMs(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->started_at === null) {
                    return null;
                }

                $end = $this->finished_at ?? now();

                return abs((int) $this->started_at->diffInMilliseconds($end, absolute: true));
            },
        );
    }

    protected function durationLabel(): Attribute
    {
        return Attribute::make(
            get: function () {
                $ms = $this->duration_ms;

                if ($ms === null) {
                    return null;
                }

                if ($ms < 1000) {
                    return $ms . ' ms';
                }

                if ($ms < 60_000) {
                    return round($ms / 1000, 1) . ' s';
                }

                return round($ms / 60_000, 1) . ' min';
            },
        );
    }

    protected function totalProcessed(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->uploaded_count + $this->deduped_count + $this->removed_count + $this->failed_count,
        );
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED], true);
    }
}
