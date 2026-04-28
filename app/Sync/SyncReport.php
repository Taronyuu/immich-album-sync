<?php

namespace App\Sync;

class SyncReport
{
    public int $uploaded = 0;
    public int $deduped = 0;
    public int $removed = 0;
    public int $failed = 0;
    public int $pushed = 0;
    public int $pushedDeduped = 0;
    public int $pushedFailed = 0;

    public function summary(): string
    {
        return sprintf(
            'pulled: %d uploaded, %d deduped, %d removed, %d failed; pushed: %d uploaded, %d deduped, %d failed',
            $this->uploaded,
            $this->deduped,
            $this->removed,
            $this->failed,
            $this->pushed,
            $this->pushedDeduped,
            $this->pushedFailed,
        );
    }
}
