<?php

namespace App\Filament\Resources\Albums\Schemas;

use App\Filament\Resources\JobRuns\JobRunResource;
use App\Models\Album;
use App\Models\JobRun;
use Cron\CronExpression;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AlbumInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Source')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('source_type')
                            ->label('Type')
                            ->badge()
                            ->formatStateUsing(fn (string $state) => match ($state) {
                                'immich-shared-link' => 'Shared link',
                                'immich-api-key' => 'API key',
                                default => $state,
                            }),

                        TextEntry::make('source_base_url')
                            ->label('URL')
                            ->copyable(),

                        TextEntry::make('source_share_key_masked')
                            ->label('Share key')
                            ->state(fn (Album $record) => self::mask($record->source_share_key))
                            ->visible(fn (Album $record) => $record->source_type === 'immich-shared-link')
                            ->placeholder('—'),

                        TextEntry::make('source_share_password_set')
                            ->label('Password')
                            ->state(fn (Album $record) => filled($record->source_share_password) ? 'Set' : 'None')
                            ->badge()
                            ->color(fn (string $state) => $state === 'Set' ? 'success' : 'gray')
                            ->visible(fn (Album $record) => $record->source_type === 'immich-shared-link'),

                        TextEntry::make('source_album_id')
                            ->label('Source album ID')
                            ->visible(fn (Album $record) => $record->source_type === 'immich-api-key'),

                        TextEntry::make('source_api_key_masked')
                            ->label('API key')
                            ->state(fn (Album $record) => self::mask($record->source_api_key))
                            ->visible(fn (Album $record) => $record->source_type === 'immich-api-key')
                            ->placeholder('—'),
                    ]),

                Section::make('Target')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('target_album_name')
                            ->label('Album name'),

                        TextEntry::make('target_album_id')
                            ->label('Immich album ID')
                            ->placeholder('Not yet created')
                            ->copyable(),

                        TextEntry::make('on_remote_delete')
                            ->label('On remote delete')
                            ->badge()
                            ->formatStateUsing(fn (string $state) => match ($state) {
                                'remove-from-album' => 'Remove from album',
                                'trash' => 'Trash on my Immich',
                                'ignore' => 'Ignore',
                                default => $state,
                            }),

                        TextEntry::make('mappings_count')
                            ->label('Synced photos')
                            ->state(fn (Album $record) => $record->mappings()->count())
                            ->badge()
                            ->color('success'),
                    ]),

                Section::make('Schedule')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('schedule')
                            ->fontFamily('mono'),

                        TextEntry::make('next_run')
                            ->label('Next run')
                            ->state(fn (Album $record) => self::nextRun($record->schedule)),

                        TextEntry::make('is_active')
                            ->label('Active')
                            ->badge()
                            ->state(fn (Album $record) => $record->is_active ? 'Yes' : 'No')
                            ->color(fn (string $state) => $state === 'Yes' ? 'success' : 'gray'),

                        TextEntry::make('last_synced_at')
                            ->label('Last synced')
                            ->dateTime()
                            ->placeholder('Never')
                            ->since(),
                    ]),

                Section::make('Recent runs')
                    ->schema([
                        TextEntry::make('recent_runs')
                            ->label('')
                            ->columnSpanFull()
                            ->state(fn (Album $record) => self::recentRunsTable($record))
                            ->html(),
                    ]),
            ]);
    }

    private static function mask(?string $value): string
    {
        if (! $value) {
            return '—';
        }

        $len = strlen($value);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }

        return substr($value, 0, 4) . str_repeat('•', $len - 8) . substr($value, -4);
    }

    private static function nextRun(string $expression): string
    {
        try {
            return (new CronExpression($expression))->getNextRunDate()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return 'Invalid cron expression';
        }
    }

    private static function recentRunsTable(Album $album): string
    {
        $runs = $album->jobRuns()->latest('id')->limit(5)->get();

        if ($runs->isEmpty()) {
            return '<div style="color: #6b7280; font-size: 0.875rem;">No runs yet — click "Run now" above.</div>';
        }

        $rows = $runs->map(function (JobRun $run) {
            $color = match ($run->status) {
                JobRun::STATUS_SUCCEEDED => '#16a34a',
                JobRun::STATUS_RUNNING => '#2563eb',
                JobRun::STATUS_QUEUED => '#6b7280',
                JobRun::STATUS_FAILED => '#dc2626',
                default => '#6b7280',
            };

            $url = JobRunResource::getUrl('view', ['record' => $run]);
            $when = e($run->started_at?->diffForHumans() ?? '—');
            $duration = e($run->duration_label ?? '—');
            $summary = sprintf('%d↑ %d= %d✕', $run->uploaded_count, $run->deduped_count, $run->failed_count);
            $status = e(ucfirst($run->status));

            return <<<HTML
                <a href="{$url}" style="display: grid; grid-template-columns: 60px 100px 1fr 120px 80px; gap: 12px; padding: 10px 12px; font-size: 0.875rem; align-items: center; border-bottom: 1px solid rgba(107,114,128,0.15); text-decoration: none; color: inherit;" onmouseover="this.style.backgroundColor='rgba(107,114,128,0.08)'" onmouseout="this.style.backgroundColor='transparent'">
                    <span style="font-family: monospace; color: #6b7280;">#{$run->id}</span>
                    <span style="color: {$color}; font-weight: 600;">{$status}</span>
                    <span style="color: #6b7280;">{$when}</span>
                    <span style="font-family: monospace;">{$summary}</span>
                    <span style="color: #6b7280; text-align: right;">{$duration}</span>
                </a>
            HTML;
        })->implode('');

        return '<div>' . $rows . '</div>';
    }
}
