<?php

namespace App\Filament\Resources\JobRuns\Tables;

use App\Models\JobRun;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class JobRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Run')
                    ->prefix('#')
                    ->sortable(),

                TextColumn::make('album.name')
                    ->label('Album')
                    ->searchable()
                    ->sortable()
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

                TextColumn::make('trigger')
                    ->badge()
                    ->color(fn (string $state) => $state === JobRun::TRIGGER_MANUAL ? 'warning' : 'gray')
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),

                TextColumn::make('summary')
                    ->state(fn (JobRun $record): string => sprintf(
                        '%d↑ %d= %d✕',
                        $record->uploaded_count,
                        $record->deduped_count,
                        $record->failed_count,
                    ))
                    ->tooltip('Uploaded · Deduped · Failed')
                    ->fontFamily('mono'),

                TextColumn::make('removed_count')
                    ->label('Removed')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('duration_label')
                    ->label('Duration')
                    ->placeholder('—'),

                TextColumn::make('started_at')
                    ->label('Started')
                    ->since()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        JobRun::STATUS_QUEUED => 'Queued',
                        JobRun::STATUS_RUNNING => 'Running',
                        JobRun::STATUS_SUCCEEDED => 'Succeeded',
                        JobRun::STATUS_FAILED => 'Failed',
                    ]),
                SelectFilter::make('trigger')
                    ->options([
                        JobRun::TRIGGER_SCHEDULED => 'Scheduled',
                        JobRun::TRIGGER_MANUAL => 'Manual',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc')
            ->poll('5s');
    }
}
