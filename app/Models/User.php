<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;

#[Fillable([
    'immich_base_url',
    'immich_user_id',
    'immich_email',
    'immich_user_name',
    'immich_api_key_id',
    'immich_api_key_encrypted',
    'is_admin',
    'last_login_at',
])]
#[Hidden(['immich_api_key_encrypted', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasName
{
    use Notifiable;

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function albums(): HasMany
    {
        return $this->hasMany(Album::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    protected function immichApiKey(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->immich_api_key_encrypted
                ? Crypt::decryptString($this->immich_api_key_encrypted)
                : null,
            set: fn (?string $value) => [
                'immich_api_key_encrypted' => $value === null ? null : Crypt::encryptString($value),
            ],
        );
    }

    public function getFilamentName(): string
    {
        return (string) ($this->immich_user_name ?: $this->immich_email);
    }
}
