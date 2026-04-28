<?php

namespace App\Filament\Widgets;

use App\Models\Album;
use App\Models\JobRun;
use App\Models\Mapping;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OverviewStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    protected ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $userId = auth()->id();

        $albumsTotal = Album::query()->where('user_id', $userId)->count();
        $albumsActive = Album::query()->where('user_id', $userId)->where('is_active', true)->count();

        $photosSynced = Mapping::query()
            ->whereIn('album_id', Album::query()->where('user_id', $userId)->select('id'))
            ->count();

        $runsTodayQuery = JobRun::query()->whereIn(
            'album_id',
            Album::query()->where('user_id', $userId)->select('id'),
        )->whereDate('created_at', today());

        $runsToday = (clone $runsTodayQuery)->count();
        $runsFailedToday = (clone $runsTodayQuery)->where('status', JobRun::STATUS_FAILED)->count();

        $lastSucceededRun = JobRun::query()
            ->whereIn('album_id', Album::query()->where('user_id', $userId)->select('id'))
            ->where('status', JobRun::STATUS_SUCCEEDED)
            ->latest('finished_at')
            ->first();

        $uploadsByDay = $this->uploadsByDay($userId);

        return [
            Stat::make('Albums', $albumsTotal)
                ->description($albumsActive === $albumsTotal
                    ? 'all active'
                    : sprintf('%d active', $albumsActive))
                ->descriptionIcon('heroicon-o-squares-2x2')
                ->color('primary'),

            Stat::make('Photos synced', $photosSynced)
                ->description('across all albums')
                ->descriptionIcon('heroicon-o-photo')
                ->chart($uploadsByDay)
                ->color('success'),

            Stat::make('Runs today', $runsToday)
                ->description($runsFailedToday > 0
                    ? sprintf('%d failed', $runsFailedToday)
                    : 'all good')
                ->descriptionIcon($runsFailedToday > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->color($runsFailedToday > 0 ? 'danger' : 'success'),

            Stat::make('Last sync', $lastSucceededRun?->finished_at?->diffForHumans() ?? 'Never')
                ->description($lastSucceededRun?->album?->name ?? 'no successful runs yet')
                ->descriptionIcon('heroicon-o-clock')
                ->color($lastSucceededRun ? 'gray' : 'warning'),
        ];
    }

    /**
     * @return array<int, int>  uploads per day for the last 7 days
     */
    private function uploadsByDay(?int $userId): array
    {
        if (! $userId) {
            return [0, 0, 0, 0, 0, 0, 0];
        }

        $rows = JobRun::query()
            ->whereIn('album_id', Album::query()->where('user_id', $userId)->select('id'))
            ->where('created_at', '>=', now()->subDays(7))
            ->where('status', JobRun::STATUS_SUCCEEDED)
            ->selectRaw('DATE(finished_at) as day, SUM(uploaded_count) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->all();

        $series = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $series[] = (int) ($rows[$day] ?? 0);
        }

        return $series;
    }
}
