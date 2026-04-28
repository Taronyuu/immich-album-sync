<?php

namespace App\Auth;

use App\Models\User;
use App\Services\Exceptions\RemoteImmichConnectException;
use App\Services\RemoteImmichConnector;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Log;

class ImmichUserProvider implements UserProvider
{
    public function __construct(
        private readonly RemoteImmichConnector $connector = new RemoteImmichConnector(),
        private readonly ImmichApiKeyProvisioner $provisioner = new ImmichApiKeyProvisioner(),
    ) {}

    public function retrieveById($identifier): ?Authenticatable
    {
        return User::query()->find($identifier);
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        $user = User::query()->find($identifier);

        if ($user === null || ! hash_equals((string) $user->getRememberToken(), (string) $token)) {
            return null;
        }

        return $user;
    }

    public function updateRememberToken(Authenticatable $user, $token): void
    {
        $user->setRememberToken($token);

        if ($user instanceof User) {
            $user->save();
        }
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $baseUrl = (string) ($credentials['immich_base_url'] ?? '');
        $email = (string) ($credentials['email'] ?? '');
        $password = (string) ($credentials['password'] ?? '');

        try {
            $loginPayload = $this->connector->login($baseUrl, $email, $password);
        } catch (RemoteImmichConnectException $e) {
            Log::info('Immich login failed', [
                'immich_base_url' => $baseUrl,
                'email' => $email,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $user = User::query()->firstOrNew([
            'immich_base_url' => $loginPayload['baseUrl'],
            'immich_user_id' => $loginPayload['userId'],
        ]);

        $user->immich_email = $loginPayload['userEmail'] ?? $email;
        $user->immich_user_name = $loginPayload['name'] ?? null;
        $user->is_admin = (bool) ($loginPayload['isAdmin'] ?? false);
        $user->last_login_at = now();

        if (empty($user->immich_api_key_encrypted)) {
            $apiKey = $this->provisioner->provision($loginPayload['baseUrl'], $loginPayload['accessToken']);
            $user->immich_api_key_id = $apiKey['id'];
            $user->immich_api_key = $apiKey['secret'];
        }

        $user->save();

        return $user;
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return $user instanceof User
            && (string) $user->immich_email === (string) ($credentials['email'] ?? '')
            && (string) $user->immich_base_url === (string) ($credentials['immich_base_url'] ?? '');
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
    }
}
