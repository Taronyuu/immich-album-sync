<?php

namespace App\Filament\Resources\JobRuns;

use App\Filament\Resources\JobRuns\Pages\ListJobRuns;
use App\Filament\Resources\JobRuns\Pages\ViewJobRun;
use App\Filament\Resources\JobRuns\Schemas\JobRunInfolist;
use App\Filament\Resources\JobRuns\Tables\JobRunsTable;
use App\Models\JobRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class JobRunResource extends Resource
{
    protected static ?string $model = JobRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Jobs';

    protected static ?string $modelLabel = 'Job';

    protected static ?string $pluralModelLabel = 'Jobs';

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return JobRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JobRunsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('album', function (Builder $q): void {
            $q->where('user_id', auth()->id());
        });
    }

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canView(Model $record): bool
    {
        return auth()->check() && $record->album?->user_id === auth()->id();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return self::canView($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobRuns::route('/'),
            'view' => ViewJobRun::route('/{record}'),
        ];
    }
}
