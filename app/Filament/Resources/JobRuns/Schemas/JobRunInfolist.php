<?php

namespace App\Filament\Resources\JobRuns\Schemas;

use App\Models\JobRun;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class JobRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Summary')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('album.name')
                            ->label('Album')
                            ->columnSpan(2),

                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state) => match ($state) {
                                JobRun::STATUS_SUCCEEDED => 'success',
                                JobRun::STATUS_RUNNING => 'info',
                                JobRun::STATUS_QUEUED => 'gray',
                                JobRun::STATUS_FAILED => 'danger',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (string $state) => ucfirst($state)),

                        TextEntry::make('trigger')
                            ->badge()
                            ->color(fn (string $state) => $state === JobRun::TRIGGER_MANUAL ? 'warning' : 'gray')
                            ->formatStateUsing(fn (string $state) => ucfirst($state)),

                        TextEntry::make('started_at')
                            ->label('Started')
                            ->dateTime('Y-m-d H:i:s')
                            ->placeholder('—'),

                        TextEntry::make('duration_label')
                            ->label('Duration')
                            ->placeholder('—'),
                    ]),

                Section::make('Pulled (remote → local)')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('uploaded_count')
                            ->label('Uploaded')
                            ->numeric(),

                        TextEntry::make('deduped_count')
                            ->label('Deduped')
                            ->numeric(),

                        TextEntry::make('removed_count')
                            ->label('Removed')
                            ->numeric(),

                        TextEntry::make('failed_count')
                            ->label('Failed')
                            ->numeric()
                            ->color(fn (?int $state) => $state > 0 ? 'danger' : null),
                    ]),

                Section::make('Pushed (local → remote)')
                    ->columns(3)
                    ->visible(fn (JobRun $record): bool => $record->pushed_count > 0
                        || $record->pushed_deduped_count > 0
                        || $record->pushed_failed_count > 0)
                    ->schema([
                        TextEntry::make('pushed_count')
                            ->label('Uploaded')
                            ->numeric(),

                        TextEntry::make('pushed_deduped_count')
                            ->label('Deduped')
                            ->numeric(),

                        TextEntry::make('pushed_failed_count')
                            ->label('Failed')
                            ->numeric()
                            ->color(fn (?int $state) => $state > 0 ? 'danger' : null),
                    ]),

                Section::make('Error')
                    ->visible(fn (JobRun $record): bool => filled($record->error_message))
                    ->schema([
                        TextEntry::make('error_message')
                            ->label('')
                            ->columnSpanFull()
                            ->color('danger')
                            ->fontFamily('mono'),
                    ]),

                Section::make('Log')
                    ->collapsible()
                    ->schema([
                        TextEntry::make('log')
                            ->label('')
                            ->placeholder('No log lines yet.')
                            ->columnSpanFull()
                            ->fontFamily('mono')
                            ->extraAttributes([
                                'style' => 'white-space: pre-wrap; max-height: 480px; overflow-y: auto;',
                            ]),
                    ]),
            ]);
    }
}
