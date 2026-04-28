<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Albums\Schemas\AlbumForm;
use App\Models\Album;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;

class QuickAddAlbumWidget extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static ?int $sort = -3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.quick-add-album-widget';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('shared_url')
                    ->label('Shared link URL')
                    ->placeholder('https://immich.example.com/share/...')
                    ->url()
                    ->required()
                    ->autocomplete('off')
                    ->autofocus()
                    ->extraInputAttributes(['inputmode' => 'url']),
            ])
            ->statePath('data');
    }

    public function create(): mixed
    {
        $data = $this->form->getState();

        $parsed = AlbumForm::parseImmichShareUrl($data['shared_url'] ?? '');

        if ($parsed === null) {
            Notification::make()
                ->title('That doesn\'t look like an Immich shared-link URL')
                ->body('Expected something like https://your-immich.example.com/share/...')
                ->danger()
                ->send();

            return null;
        }

        $host = parse_url($parsed['base_url'], PHP_URL_HOST) ?: 'remote';
        $shortKey = substr($parsed['share_key'], 0, 6);
        $name = "{$host} · {$shortKey}";

        $album = Album::create([
            'user_id' => auth()->id(),
            'name' => $this->uniqueName($name, auth()->id()),
            'schedule' => '*/15 * * * *',
            'source_type' => 'immich-shared-link',
            'source_base_url' => $parsed['base_url'],
            'source_share_key' => $parsed['share_key'],
            'target_album_name' => $name,
            'on_remote_delete' => 'remove-from-album',
            'is_active' => false,
        ]);

        $this->form->fill();

        Notification::make()
            ->title('Album created')
            ->body('Add a password if needed, then activate it.')
            ->success()
            ->send();

        return redirect()->to(AlbumResource::getUrl('edit', ['record' => $album]));
    }

    private function uniqueName(string $base, int $userId): string
    {
        $name = $base;
        $i = 2;
        while (Album::query()->where('user_id', $userId)->where('name', $name)->exists()) {
            $name = "{$base} ({$i})";
            $i++;
        }

        return $name;
    }
}
