<?php

namespace App\Filament\Resources\Albums\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class AlbumForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Album')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Basics')
                            ->icon(Heroicon::OutlinedSquares2x2)
                            ->schema([
                                TextInput::make('name')
                                    ->label('Album name')
                                    ->helperText('Shown in this app — only you see it.')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull(),

                                TextInput::make('schedule')
                                    ->required()
                                    ->default('*/15 * * * *')
                                    ->helperText('Cron expression. Default: every 15 minutes.')
                                    ->columnSpan(1),

                                Toggle::make('is_active')
                                    ->label('Active')
                                    ->helperText('Enable scheduled syncing for this album.')
                                    ->required()
                                    ->default(true)
                                    ->columnSpan(1),
                            ])
                            ->columns(2),

                        Tab::make('Source')
                            ->icon(Heroicon::OutlinedArrowDownTray)
                            ->schema([
                                Select::make('source_type')
                                    ->label('Source type')
                                    ->required()
                                    ->live()
                                    ->options([
                                        'immich-shared-link' => 'Another Immich · public shared link',
                                        'immich-api-key' => 'Another Immich · API key + album ID',
                                    ])
                                    ->columnSpanFull(),

                                TextInput::make('source_base_url')
                                    ->label('Source Immich URL')
                                    ->url()
                                    ->required()
                                    ->placeholder('https://demo.immich.app')
                                    ->helperText('Base URL of the Immich you are pulling from. Tip: paste the full shared-link URL here and we will split it for you.')
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(self::splitSharedLinkUrl(...))
                                    ->columnSpanFull(),

                                TextInput::make('source_share_key')
                                    ->label('Shared link key')
                                    ->password()
                                    ->revealable()
                                    ->visible(fn ($get) => $get('source_type') === 'immich-shared-link')
                                    ->required(fn ($get) => $get('source_type') === 'immich-shared-link')
                                    ->helperText('The part after /share/ in the URL. You can also paste the full shared-link URL here.')
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(self::splitSharedLinkKey(...))
                                    ->columnSpanFull(),

                                TextInput::make('source_share_password')
                                    ->label('Shared link password')
                                    ->password()
                                    ->revealable()
                                    ->visible(fn ($get) => $get('source_type') === 'immich-shared-link')
                                    ->helperText('Only required if the link is password-protected.')
                                    ->columnSpanFull(),

                                TextInput::make('source_api_key')
                                    ->label('API key on the source Immich')
                                    ->password()
                                    ->revealable()
                                    ->visible(fn ($get) => $get('source_type') === 'immich-api-key')
                                    ->required(fn ($get) => $get('source_type') === 'immich-api-key')
                                    ->helperText('Generate this on the source Immich (Account → API Keys) with album.read + asset.read + asset.download.')
                                    ->columnSpanFull(),

                                TextInput::make('source_album_id')
                                    ->label('Album ID on the source Immich')
                                    ->visible(fn ($get) => $get('source_type') === 'immich-api-key')
                                    ->required(fn ($get) => $get('source_type') === 'immich-api-key')
                                    ->helperText('UUID of the album you want to mirror.')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        Tab::make('Target')
                            ->icon(Heroicon::OutlinedArrowUpTray)
                            ->schema([
                                TextInput::make('target_album_name')
                                    ->label('Album name on your Immich')
                                    ->helperText('Will be created lazily on the first sync.')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull(),

                                Select::make('on_remote_delete')
                                    ->label('When the source removes a photo')
                                    ->required()
                                    ->default('remove-from-album')
                                    ->options([
                                        'remove-from-album' => 'Remove from this album (keep in library)',
                                        'trash' => 'Move to trash on my Immich',
                                        'ignore' => 'Do nothing (leave in album)',
                                    ])
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                    ])
                    ->persistTabInQueryString(),
            ]);
    }

    public static function splitSharedLinkUrl(?string $state, callable $set): void
    {
        $parsed = self::parseImmichShareUrl($state);

        if ($parsed === null) {
            return;
        }

        $set('source_base_url', $parsed['base_url']);
        $set('source_share_key', $parsed['share_key']);
    }

    public static function splitSharedLinkKey(?string $state, callable $set, callable $get): void
    {
        $parsed = self::parseImmichShareUrl($state);

        if ($parsed === null) {
            return;
        }

        $set('source_share_key', $parsed['share_key']);

        if (empty($get('source_base_url'))) {
            $set('source_base_url', $parsed['base_url']);
        }
    }

    /**
     * @return array{base_url: string, share_key: string}|null
     */
    public static function parseImmichShareUrl(?string $value): ?array
    {
        if (! is_string($value) || ! preg_match('#^https?://#i', $value) || ! str_contains($value, '/share/')) {
            return null;
        }

        $parts = parse_url($value);
        if (! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return null;
        }

        $key = trim(substr($parts['path'], strpos($parts['path'], '/share/') + strlen('/share/')), '/');
        if ($key === '') {
            return null;
        }

        $base = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $base .= ':' . $parts['port'];
        }

        return ['base_url' => $base, 'share_key' => $key];
    }
}
