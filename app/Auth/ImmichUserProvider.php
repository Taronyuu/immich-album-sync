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
        $apiKey = trim((string) ($credentials['immich_api_key'] ?? ''));
        $email = (string) ($credentials['email'] ?? '');
        $password = (string) ($credentials['password'] ?? '');

        if ($apiKey !== '') {
            return $this->retrieveViaApiKey($baseUrl, $apiKey, $email);
        }

        return $this->retrieveViaPassword($baseUrl, $email, $password);
    }

    private function retrieveViaPassword(string $baseUrl, string $email, string $password): ?Authenticatable
    {
        try {
            $loginPayload = $this->connector->login($baseUrl, $email, $password);
        } catch (RemoteImmichConnectException $e) {
            if ($e->isUnreachable()) {
                throw $e;
            }

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
            $apiKey = $this->provisioner->provisionWithBearerToken($loginPayload['baseUrl'], $loginPayload['accessToken']);
            $user->immich_api_key_id = $apiKey['id'];
            $user->immich_api_key = $apiKey['secret'];
        }

        $user->save();

        return $user;
    }

    private function retrieveViaApiKey(string $baseUrl, string $apiKey, string $email): ?Authenticatable
    {
        try {
            $loginPayload = $this->connector->loginWithApiKey($baseUrl, $apiKey);
        } catch (RemoteImmichConnectException $e) {
            if ($e->isUnreachable()) {
                throw $e;
            }

            Log::info('Immich API-key login failed', [
                'immich_base_url' => $baseUrl,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $remoteEmail = (string) ($loginPayload['userEmail'] ?? '');
        $userEmail = $email !== '' ? $email : $remoteEmail;

        if ($email !== '' && $remoteEmail !== '' && strcasecmp($email, $remoteEmail) !== 0) {
            Log::info('Immich API-key login email mismatch', [
                'immich_base_url' => $baseUrl,
                'submitted_email' => $email,
                'remote_email' => $remoteEmail,
            ]);

            return null;
        }

        $user = User::query()->firstOrNew([
            'immich_base_url' => $loginPayload['baseUrl'],
            'immich_user_id' => $loginPayload['userId'],
        ]);

        $user->immich_email = $userEmail;
        $user->immich_user_name = $loginPayload['name'] ?? null;
        $user->is_admin = (bool) ($loginPayload['isAdmin'] ?? false);
        $user->last_login_at = now();

        if (empty($user->immich_api_key_encrypted)) {
            try {
                $provisioned = $this->provisioner->provisionWithApiKey($loginPayload['baseUrl'], $apiKey);
            } catch (\Illuminate\Http\Client\RequestException $e) {
                $status = $e->response?->status();
                $message = $status === 403
                    ? 'The API key is missing the `apiKey.create` scope, so a sync-scoped key could not be provisioned.'
                    : ($status !== null ? "Failed to provision sync API key (HTTP {$status})." : 'Failed to provision sync API key.');
                throw new RemoteImmichConnectException($message, 0, $e);
            }

            $user->immich_api_key_id = $provisioned['id'];
            $user->immich_api_key = $provisioned['secret'];
        }

        $user->save();

        return $user;
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if ((string) $user->immich_base_url !== (string) ($credentials['immich_base_url'] ?? '')) {
            return false;
        }

        $apiKey = trim((string) ($credentials['immich_api_key'] ?? ''));

        if ($apiKey !== '') {
            return true;
        }

        return (string) $user->immich_email === (string) ($credentials['email'] ?? '');
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
    }
}
