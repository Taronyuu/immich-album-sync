<?php

namespace App\Services;

use App\Auth\ImmichApiKeyProvisioner;
use App\Services\DTO\RemoteImmichConnection;
use App\Services\Exceptions\RemoteImmichConnectException;
use App\Sync\ImmichClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class RemoteImmichConnector
{
    public function __construct(
        private readonly ImmichApiKeyProvisioner $provisioner = new ImmichApiKeyProvisioner(),
    ) {}

    public function login(string $baseUrl, string $email, string $password): array
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $email = trim($email);

        if ($baseUrl === '' || $email === '' || $password === '') {
            throw new RemoteImmichConnectException('URL, email, and password are all required.');
        }

        try {
            $payload = ImmichClient::anonymous($baseUrl)->login($email, $password);
        } catch (ConnectionException $e) {
            throw new RemoteImmichConnectException(
                "Could not reach Immich at {$baseUrl}. Check the URL (including port) is correct and reachable from this server.",
                0,
                $e,
            );
        } catch (RequestException $e) {
            $status = $e->response?->status();
            throw new RemoteImmichConnectException(
                $status !== null
                    ? "Immich login failed (HTTP {$status})."
                    : 'Immich login failed.',
                0,
                $e,
            );
        }

        if (! is_array($payload) || empty($payload['accessToken']) || empty($payload['userId'])) {
            throw new RemoteImmichConnectException('Immich login response was missing accessToken or userId.');
        }

        $payload['baseUrl'] = $baseUrl;

        return $payload;
    }

    public function loginWithApiKey(string $baseUrl, string $apiKey): array
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $apiKey = trim($apiKey);

        if ($baseUrl === '' || $apiKey === '') {
            throw new RemoteImmichConnectException('URL and API key are both required.');
        }

        try {
            $payload = ImmichClient::withApiKey($baseUrl, $apiKey)->me();
        } catch (ConnectionException $e) {
            throw new RemoteImmichConnectException(
                "Could not reach Immich at {$baseUrl}. Check the URL (including port) is correct and reachable from this server.",
                0,
                $e,
            );
        } catch (RequestException $e) {
            $status = $e->response?->status();
            $message = match ($status) {
                401 => 'Immich rejected the API key. Make sure you pasted the full key and that it has not been revoked.',
                403 => 'The API key is missing required scopes. It needs the 8 sync scopes (asset.upload/read/download, album.create/read/update, albumAsset.create/delete) plus `user.read`.',
                default => $status !== null
                    ? "Immich API-key login failed (HTTP {$status})."
                    : 'Immich API-key login failed.',
            };
            throw new RemoteImmichConnectException($message, 0, $e);
        }

        if (! is_array($payload) || empty($payload['id'])) {
            throw new RemoteImmichConnectException('Immich /api/users/me response was missing the user id.');
        }

        return [
            'baseUrl' => $baseUrl,
            'userId' => $payload['id'],
            'userEmail' => $payload['email'] ?? null,
            'name' => $payload['name'] ?? null,
            'isAdmin' => (bool) ($payload['isAdmin'] ?? false),
            'bootstrapApiKey' => $apiKey,
        ];
    }

    public function connect(string $baseUrl, string $email, string $password): RemoteImmichConnection
    {
        $login = $this->login($baseUrl, $email, $password);

        $key = $this->provisioner->provisionWithBearerToken($login['baseUrl'], $login['accessToken']);

        return new RemoteImmichConnection(
            baseUrl: $login['baseUrl'],
            immichUserId: $login['userId'],
            email: $login['userEmail'] ?? $email,
            name: $login['name'] ?? null,
            isAdmin: (bool) ($login['isAdmin'] ?? false),
            apiKeyId: $key['id'],
            apiKeySecret: $key['secret'],
        );
    }

}
