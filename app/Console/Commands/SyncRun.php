<?php

namespace App\Console\Commands;

use App\Jobs\RunSyncJob;
use App\Models\Album;
use App\Models\JobRun;
use App\Sync\SyncEngine;
use Cron\CronExpression;
use Illuminate\Console\Command;

class SyncRun extends Command
{
    protected $signature = 'sync:run
                            {--album= : Run only this album ID}
                            {--once : Run synchronously instead of dispatching to the queue}
                            {--all : Run all active albums regardless of schedule}';

    protected $description = 'Dispatch album syncs whose schedule matches now (or run a specific one).';

    public function handle(SyncEngine $engine): int
    {
        $query = Album::query()->where('is_active', true);

        if ($id = $this->option('album')) {
            $query->where('id', $id);
        }

        $albums = $query->get();

        if ($albums->isEmpty()) {
            $this->info('No matching active albums.');

            return self::SUCCESS;
        }

        $now = now();
        $dispatched = 0;

        foreach ($albums as $album) {
            $isManual = $this->option('all') || $this->option('album');

            if (! $isManual && ! $this->scheduleMatches($album->schedule, $now)) {
                continue;
            }

            $trigger = $isManual ? JobRun::TRIGGER_MANUAL : JobRun::TRIGGER_SCHEDULED;

            if ($this->option('once')) {
                $this->info("Running album #{$album->id} ({$album->name}) inline...");
                $run = JobRun::create([
                    'album_id' => $album->id,
                    'status' => JobRun::STATUS_RUNNING,
                    'trigger' => $trigger,
                    'started_at' => now(),
                ]);
                $report = $engine->run($album->loadMissing('user'), $run);
                $this->line('  → ' . $report->summary());
            } else {
                $run = RunSyncJob::dispatchForAlbum($album->id, $trigger);
                $this->info("Dispatched album #{$album->id} ({$album->name}) — run #{$run->id}");
            }

            $dispatched++;
        }

        $this->info("Done. {$dispatched} album(s) processed.");

        return self::SUCCESS;
    }

    private function scheduleMatches(string $expression, $time): bool
    {
        try {
            return (new CronExpression($expression))->isDue($time);
        } catch (\Throwable) {
            return false;
        }
    }
}
