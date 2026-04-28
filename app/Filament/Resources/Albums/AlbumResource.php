<?php

namespace App\Filament\Resources\Albums;

use App\Filament\Resources\Albums\Pages\CreateAlbum;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Filament\Resources\Albums\Pages\ListAlbums;
use App\Filament\Resources\Albums\Pages\ViewAlbum;
use App\Filament\Resources\Albums\Schemas\AlbumForm;
use App\Filament\Resources\Albums\Schemas\AlbumInfolist;
use App\Filament\Resources\Albums\Tables\AlbumsTable;
use App\Models\Album;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AlbumResource extends Resource
{
    protected static ?string $model = Album::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Albums';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return AlbumForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AlbumInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AlbumsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if ($userId = auth()->id()) {
            $query->where('user_id', $userId);
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canView(Model $record): bool
    {
        return auth()->check() && $record->user_id === auth()->id();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canView($record);
    }

    public static function canDelete(Model $record): bool
    {
        return self::canView($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAlbums::route('/'),
            'create' => CreateAlbum::route('/create'),
            'view' => ViewAlbum::route('/{record}'),
            'edit' => EditAlbum::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return static::getEloquentQuery();
    }
}
