<?php

namespace App\Filament\Resources\JobRuns\Pages;

use App\Filament\Resources\JobRuns\JobRunResource;
use App\Jobs\RunSyncJob;
use App\Models\JobRun;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewJobRun extends ViewRecord
{
    protected static string $resource = JobRunResource::class;

    public ?string $pollingInterval = '5s';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rerun')
                ->label('Re-run')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (JobRun $record) => $record->album !== null)
                ->action(function (JobRun $record) {
                    $newRun = RunSyncJob::dispatchForAlbum(
                        $record->album_id,
                        JobRun::TRIGGER_MANUAL,
                    );

                    Notification::make()
                        ->title('Re-run queued')
                        ->body("New run #{$newRun->id} queued for «{$record->album->name}».")
                        ->success()
                        ->send();

                    return redirect()->to(JobRunResource::getUrl('view', ['record' => $newRun]));
                }),
        ];
    }
}
