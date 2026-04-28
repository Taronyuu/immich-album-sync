<?php

namespace App\Filament\Resources\Albums\Pages;

use App\Filament\Resources\Albums\AlbumResource;
use App\Jobs\RunSyncJob;
use App\Models\Album;
use App\Models\JobRun;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditAlbum extends EditRecord
{
    protected static string $resource = AlbumResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open_in_immich')
                ->label('Open in Immich')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (Album $record) => filled($record->target_album_id))
                ->url(
                    fn (Album $record) => rtrim($record->user->immich_base_url, '/')
                        . '/albums/' . $record->target_album_id,
                    shouldOpenInNewTab: true,
                ),

            Action::make('run')
                ->label('Run now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Run sync now?')
                ->action(function (Album $record): void {
                    $run = RunSyncJob::dispatchForAlbum($record->id, JobRun::TRIGGER_MANUAL);

                    Notification::make()
                        ->title('Sync queued')
                        ->body("Run #{$run->id} queued — see the Jobs tab for progress.")
                        ->success()
                        ->send();
                }),

            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
