<?php

namespace App\Filament\Resources\Albums\Pages;

use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\JobRuns\JobRunResource;
use App\Jobs\RunSyncJob;
use App\Models\Album;
use App\Models\JobRun;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewAlbum extends ViewRecord
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
                ->action(function (Album $record) {
                    $run = RunSyncJob::dispatchForAlbum($record->id, JobRun::TRIGGER_MANUAL);

                    Notification::make()
                        ->title('Sync queued')
                        ->body("Run #{$run->id} queued.")
                        ->success()
                        ->send();

                    return redirect()->to(JobRunResource::getUrl('view', ['record' => $run]));
                }),

            EditAction::make(),
            DeleteAction::make(),
        ];
    }
}
