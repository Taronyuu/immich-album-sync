<?php

namespace App\Jobs;

use App\Models\Album;
use App\Models\JobRun;
use App\Sync\SyncEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(
        public int $albumId,
        public string $trigger = JobRun::TRIGGER_SCHEDULED,
        public ?int $runId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'album-' . $this->albumId;
    }

    public function handle(SyncEngine $engine): void
    {
        $run = $this->runId !== null ? JobRun::find($this->runId) : null;
        $album = Album::query()->with('user')->find($this->albumId);

        if ($album === null) {
            $this->markRunFailed($run, 'Album was deleted before the run could start.');
            Log::warning('Album not found; skipping', ['album_id' => $this->albumId, 'run_id' => $this->runId]);

            return;
        }

        if ($this->trigger === JobRun::TRIGGER_SCHEDULED && ! $album->is_active) {
            $this->markRunFailed($run, 'Album is inactive; scheduled run skipped.');

            return;
        }

        if ($album->user === null) {
            $this->markRunFailed($run, 'Album has no associated user.');
            Log::warning('Album has no user; skipping', ['album_id' => $album->id]);

            return;
        }

        $run = $run ?? JobRun::create([
            'album_id' => $album->id,
            'status' => JobRun::STATUS_RUNNING,
            'trigger' => $this->trigger,
            'started_at' => now(),
        ]);

        $startMemory = memory_get_peak_usage(true);

        $engine->run($album, $run);

        Log::info('Album sync completed', [
            'album_id' => $album->id,
            'job_run_id' => $run->id,
            'trigger' => $this->trigger,
            'peak_memory_mb' => round((memory_get_peak_usage(true) - $startMemory) / 1024 / 1024, 1),
        ]);
    }

    public static function dispatchForAlbum(int $albumId, string $trigger = JobRun::TRIGGER_SCHEDULED): JobRun
    {
        $run = JobRun::create([
            'album_id' => $albumId,
            'status' => JobRun::STATUS_QUEUED,
            'trigger' => $trigger,
        ]);

        self::dispatch($albumId, $trigger, $run->id);

        return $run;
    }

    private function markRunFailed(?JobRun $run, string $message): void
    {
        if ($run === null) {
            return;
        }

        $run->update([
            'status' => JobRun::STATUS_FAILED,
            'started_at' => $run->started_at ?? now(),
            'finished_at' => now(),
            'error_message' => $message,
            'log' => '[' . now()->format('H:i:s') . '] [INFO] ' . $message . "\n",
        ]);
    }
}
