<?php

namespace App\Filament\Pages\Auth;

use App\Services\Exceptions\RemoteImmichConnectException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;

class ImmichLogin extends BaseLogin
{
    private const URL_COOKIE = 'ias_last_immich_url';

    public function getHeading(): string
    {
        return 'Sign in';
    }

    public function getSubheading(): ?string
    {
        return 'Mirror photo albums into your Immich.';
    }

    public function mount(): void
    {
        parent::mount();

        $rememberedUrl = (string) Cookie::get(self::URL_COOKIE, '');

        if ($rememberedUrl !== '' && empty($this->data['immich_base_url'] ?? null)) {
            $this->form->fill([
                ...$this->data ?? [],
                'immich_base_url' => $rememberedUrl,
            ]);
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getImmichBaseUrlComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent()
                    ->required(fn (Get $get) => blank($get('immich_api_key'))),
                $this->getImmichApiKeyComponent(),
                $this->getRememberFormComponent(),
            ]);
    }

    protected function getImmichBaseUrlComponent(): Component
    {
        return TextInput::make('immich_base_url')
            ->label('Immich URL')
            ->placeholder('https://immich.example.com')
            ->url()
            ->required()
            ->autofocus()
            ->extraInputAttributes(['inputmode' => 'url']);
    }

    protected function getImmichApiKeyComponent(): Component
    {
        return TextInput::make('immich_api_key')
            ->label('Or sign in with an Immich API key')
            ->helperText('For OIDC-only users — paste a key with `user.read` and `apiKey.create` scopes. Leave blank to sign in with email + password.')
            ->password()
            ->revealable()
            ->autocomplete('off');
    }

    public function authenticate(): ?LoginResponse
    {
        $data = $this->form->getState();

        $immichBaseUrl = rtrim((string) $data['immich_base_url'], '/');
        $apiKey = trim((string) ($data['immich_api_key'] ?? ''));

        $credentials = array_filter([
            'immich_base_url' => $immichBaseUrl,
            'email' => $data['email'] ?? '',
            'password' => $data['password'] ?? '',
            'immich_api_key' => $apiKey,
        ], fn ($value) => $value !== '' && $value !== null);

        try {
            $ok = Auth::attempt($credentials, $data['remember'] ?? false);
        } catch (RemoteImmichConnectException $e) {
            throw ValidationException::withMessages([
                'data.immich_base_url' => $e->getMessage(),
            ]);
        }

        if (! $ok) {
            $this->throwFailureValidationException();
        }

        Cookie::queue(self::URL_COOKIE, $immichBaseUrl, 60 * 24 * 30);

        session()->regenerate();

        return app(LoginResponse::class);
    }

    protected function throwFailureValidationException(): never
    {
        Notification::make()
            ->title(__('filament-panels::auth/pages/login.notifications.failed.title'))
            ->danger()
            ->send();

        throw ValidationException::withMessages([
            'data.email' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }
}
