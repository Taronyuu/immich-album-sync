<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ImmichLogin extends BaseLogin
{
    public function getHeading(): string
    {
        return 'Sign in';
    }

    public function getSubheading(): ?string
    {
        return 'Mirror photo albums into your Immich.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getImmichBaseUrlComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
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

    public function authenticate(): ?LoginResponse
    {
        $data = $this->form->getState();

        $credentials = [
            'immich_base_url' => rtrim((string) $data['immich_base_url'], '/'),
            'email' => $data['email'],
            'password' => $data['password'],
        ];

        if (! Auth::attempt($credentials, $data['remember'] ?? false)) {
            $this->throwFailureValidationException();
        }

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
