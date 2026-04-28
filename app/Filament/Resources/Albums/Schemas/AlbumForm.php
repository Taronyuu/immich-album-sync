<?php

namespace App\Filament\Resources\Albums\Schemas;

use App\Models\Album;
use App\Services\Exceptions\RemoteImmichConnectException;
use App\Services\RemoteImmichConnector;
use App\Sync\ImmichClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

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
                                        Album::SOURCE_SHARED_LINK => 'Public shared link (read-only)',
                                        Album::SOURCE_API_KEY => 'Connected Immich account (two-way capable)',
                                    ])
                                    ->default(Album::SOURCE_SHARED_LINK)
                                    ->columnSpanFull(),

                                Select::make('direction')
                                    ->label('Sync direction')
                                    ->required()
                                    ->live()
                                    ->default(Album::DIRECTION_PULL)
                                    ->options([
                                        Album::DIRECTION_PULL => 'Pull only — mirror remote into your album',
                                        Album::DIRECTION_PUSH => 'Push only — upload your album to the remote',
                                        Album::DIRECTION_BOTH => 'Two-way — keep both sides in sync',
                                    ])
                                    ->visible(fn ($get) => $get('source_type') === Album::SOURCE_API_KEY)
                                    ->helperText(fn ($get) => match ($get('direction')) {
                                        Album::DIRECTION_PUSH, Album::DIRECTION_BOTH => new HtmlString(
                                            '<span class="text-warning-700 dark:text-warning-300">'
                                            . 'Heads-up: every asset already in your target album that hasn\'t been mapped yet will be uploaded to the remote on the next run.'
                                            . '</span>'
                                        ),
                                        default => null,
                                    })
                                    ->columnSpanFull(),

                                TextInput::make('source_base_url')
                                    ->label('Source Immich URL')
                                    ->url()
                                    ->required()
                                    ->placeholder('https://demo.immich.app')
                                    ->helperText('Base URL of the remote Immich. Tip: paste the full shared-link URL here and we will split it for you.')
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(self::splitSharedLinkUrl(...))
                                    ->columnSpanFull(),

                                TextInput::make('source_share_key')
                                    ->label('Shared link key')
                                    ->password()
                                    ->revealable()
                                    ->visible(fn ($get) => $get('source_type') === Album::SOURCE_SHARED_LINK)
                                    ->required(fn ($get) => $get('source_type') === Album::SOURCE_SHARED_LINK)
                                    ->helperText('The part after /share/ in the URL. You can also paste the full shared-link URL here.')
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(self::splitSharedLinkKey(...))
                                    ->columnSpanFull(),

                                TextInput::make('source_share_password')
                                    ->label('Shared link password')
                                    ->password()
                                    ->revealable()
                                    ->visible(fn ($get) => $get('source_type') === Album::SOURCE_SHARED_LINK)
                                    ->helperText('Only required if the link is password-protected.')
                                    ->columnSpanFull(),

                                Section::make('Connect with email + password')
                                    ->visible(fn ($get) => $get('source_type') === Album::SOURCE_API_KEY)
                                    ->description('We round-trip your credentials to the remote Immich, provision a scoped API key, and store it encrypted. Your password is never persisted.')
                                    ->schema([
                                        Placeholder::make('connection_status')
                                            ->label('Connection status')
                                            ->content(fn ($get) => filled($get('source_account_email'))
                                                ? new HtmlString('<span class="text-success-700 dark:text-success-300">✓ Connected as <strong>' . e($get('source_account_email')) . '</strong></span>')
                                                : 'Not connected')
                                            ->columnSpanFull(),

                                        TextInput::make('_connect_email')
                                            ->label('Email')
                                            ->email()
                                            ->dehydrated(false)
                                            ->columnSpan(1),

                                        TextInput::make('_connect_password')
                                            ->label('Password')
                                            ->password()
                                            ->revealable()
                                            ->dehydrated(false)
                                            ->columnSpan(1),

                                        Actions::make([
                                            Action::make('connect_remote')
                                                ->label('Connect & provision API key')
                                                ->icon(Heroicon::OutlinedKey)
                                                ->color('primary')
                                                ->action(self::handleConnect(...)),
                                        ])->columnSpanFull(),
                                    ])
                                    ->columns(2),

                                Select::make('source_album_id')
                                    ->label('Album on the remote Immich')
                                    ->visible(fn ($get) => $get('source_type') === Album::SOURCE_API_KEY)
                                    ->required(fn ($get) => $get('source_type') === Album::SOURCE_API_KEY)
                                    ->searchable()
                                    ->options(self::loadRemoteAlbumOptions(...))
                                    ->helperText('Pick from albums available on the remote (owned + shared with you). If empty, connect first or check the API key.')
                                    ->columnSpanFull(),

                                Hidden::make('source_account_email'),
                                Hidden::make('source_account_user_id'),
                                Hidden::make('_remote_albums')->dehydrated(false),

                                Section::make('Advanced: paste an existing API key')
                                    ->visible(fn ($get) => $get('source_type') === Album::SOURCE_API_KEY)
                                    ->collapsible()
                                    ->collapsed()
                                    ->schema([
                                        TextInput::make('source_api_key')
                                            ->label('API key on the remote Immich')
                                            ->password()
                                            ->revealable()
                                            ->required(fn ($get) => $get('source_type') === Album::SOURCE_API_KEY)
                                            ->helperText('Use this if you already have a key. The Connect button populates it for you.')
                                            ->columnSpanFull(),
                                    ]),
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

    private static function handleConnect(callable $get, callable $set): void
    {
        $baseUrl = (string) ($get('source_base_url') ?? '');
        $email = (string) ($get('_connect_email') ?? '');
        $password = (string) ($get('_connect_password') ?? '');

        try {
            $connection = (new RemoteImmichConnector())->connect($baseUrl, $email, $password);
        } catch (RemoteImmichConnectException $e) {
            Notification::make()
                ->title('Could not connect')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $set('source_base_url', $connection->baseUrl);
        $set('source_api_key', $connection->apiKeySecret);
        $set('source_account_email', $connection->email);
        $set('source_account_user_id', $connection->immichUserId);
        $set('_connect_password', null);

        $albums = self::fetchRemoteAlbums($connection->baseUrl, $connection->apiKeySecret);
        $set('_remote_albums', $albums);
        $set('source_album_id', null);

        Notification::make()
            ->title('Connected')
            ->body('Provisioned a scoped API key as ' . $connection->email . '. ' . count($albums) . ' album(s) available — pick one below.')
            ->success()
            ->send();
    }

    /**
     * @return array<string, string>
     */
    private static function loadRemoteAlbumOptions(callable $get): array
    {
        $cached = $get('_remote_albums');
        if (is_array($cached) && ! empty($cached)) {
            return $cached;
        }

        $baseUrl = (string) ($get('source_base_url') ?? '');
        $apiKey = (string) ($get('source_api_key') ?? '');

        if ($baseUrl === '' || $apiKey === '') {
            return [];
        }

        return self::fetchRemoteAlbums($baseUrl, $apiKey);
    }

    /**
     * @return array<string, string>
     */
    private static function fetchRemoteAlbums(string $baseUrl, string $apiKey): array
    {
        try {
            $client = ImmichClient::withApiKey($baseUrl, $apiKey);
            $owned = $client->listMyAlbums();
            $shared = $client->listSharedAlbums();
        } catch (\Throwable) {
            return [];
        }

        $byId = [];
        foreach ($owned as $a) {
            if (! isset($a['id'])) continue;
            $byId[$a['id']] = $a['albumName'] ?? $a['id'];
        }
        foreach ($shared as $a) {
            if (! isset($a['id']) || isset($byId[$a['id']])) continue;
            $byId[$a['id']] = ($a['albumName'] ?? $a['id']) . ' (shared)';
        }

        return $byId;
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
