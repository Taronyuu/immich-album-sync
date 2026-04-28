<?php

namespace App\Filament\Resources\Albums\Tables;

use App\Filament\Resources\JobRuns\JobRunResource;
use App\Jobs\RunSyncJob;
use App\Models\Album;
use App\Models\JobRun;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AlbumsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('latestJobRun'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('source_type')
                    ->label('Source')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'immich-shared-link' => 'Shared link',
                        'immich-api-key' => 'API key',
                        default => $state,
                    }),

                TextColumn::make('target_album_name')
                    ->label('Target album')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('schedule')
                    ->fontFamily('mono')
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),

                TextColumn::make('latest_run_status')
                    ->label('Last run')
                    ->state(fn (Album $record): ?string => $record->latestJobRun?->status)
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        JobRun::STATUS_SUCCEEDED => 'success',
                        JobRun::STATUS_RUNNING => 'info',
                        JobRun::STATUS_QUEUED => 'gray',
                        JobRun::STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state) => $state ? ucfirst($state) : null)
                    ->placeholder('Never run')
                    ->url(fn (Album $record) => $record->latestJobRun
                        ? JobRunResource::getUrl('view', ['record' => $record->latestJobRun])
                        : null),

                TextColumn::make('last_synced_at')
                    ->label('Last synced')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('run')
                    ->label('Run now')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Run sync now?')
                    ->modalDescription(fn (Album $record) => "Pull from {$record->source_base_url} into «{$record->target_album_name}».")
                    ->modalSubmitActionLabel('Run')
                    ->action(function (Album $record): void {
                        $run = RunSyncJob::dispatchForAlbum($record->id, JobRun::TRIGGER_MANUAL);

                        Notification::make()
                            ->title('Sync queued')
                            ->body("Run #{$run->id} for «{$record->name}» — see the Jobs tab for progress.")
                            ->success()
                            ->send();
                    }),

                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No albums yet')
            ->emptyStateDescription('Mirror your first shared album from another Immich instance.')
            ->emptyStateActions([
                CreateAction::make()->label('Create your first album'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedSquares2x2)
            ->defaultSort('updated_at', 'desc');
    }
}
