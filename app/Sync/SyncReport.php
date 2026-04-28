<?php

namespace App\Sync;

class SyncReport
{
    public int $uploaded = 0;
    public int $deduped = 0;
    public int $removed = 0;
    public int $failed = 0;

    public function summary(): string
    {
        return sprintf(
            '%d uploaded, %d deduped, %d removed, %d failed',
            $this->uploaded,
            $this->deduped,
            $this->removed,
            $this->failed,
        );
    }
}
