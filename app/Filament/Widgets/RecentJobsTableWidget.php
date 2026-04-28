<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\JobRuns\JobRunResource;
use App\Models\Album;
use App\Models\JobRun;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentJobsTableWidget extends TableWidget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent activity')
            ->description('Last 10 sync runs across your albums.')
            ->query(
                JobRun::query()
                    ->whereIn('album_id', Album::query()->where('user_id', auth()->id())->select('id'))
                    ->latest('id')
                    ->limit(10),
            )
            ->columns([
                TextColumn::make('id')
                    ->label('Run')
                    ->prefix('#'),

                TextColumn::make('album.name')
                    ->label('Album')
                    ->wrap(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        JobRun::STATUS_SUCCEEDED => 'success',
                        JobRun::STATUS_RUNNING => 'info',
                        JobRun::STATUS_QUEUED => 'gray',
                        JobRun::STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),

                TextColumn::make('summary')
                    ->state(fn (JobRun $record): string => sprintf(
                        '%d↑ %d= %d✕',
                        $record->uploaded_count,
                        $record->deduped_count,
                        $record->failed_count,
                    ))
                    ->fontFamily('mono'),

                TextColumn::make('duration_label')
                    ->label('Duration')
                    ->placeholder('—'),

                TextColumn::make('started_at')
                    ->label('Started')
                    ->since()
                    ->placeholder('—'),
            ])
            ->recordUrl(fn (JobRun $record): string => JobRunResource::getUrl('view', ['record' => $record]))
            ->paginated(false)
            ->poll('5s');
    }
}
