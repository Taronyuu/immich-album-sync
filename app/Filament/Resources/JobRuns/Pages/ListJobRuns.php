<?php

namespace App\Filament\Resources\JobRuns\Pages;

use App\Filament\Resources\JobRuns\JobRunResource;
use Filament\Resources\Pages\ListRecords;

class ListJobRuns extends ListRecords
{
    protected static string $resource = JobRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
