<?php

namespace App\Services;

use App\Auth\ImmichApiKeyProvisioner;
use App\Services\DTO\RemoteImmichConnection;
use App\Services\Exceptions\RemoteImmichConnectException;
use App\Sync\ImmichClient;
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

    public function connect(string $baseUrl, string $email, string $password): RemoteImmichConnection
    {
        $login = $this->login($baseUrl, $email, $password);

        $key = $this->provisioner->provision($login['baseUrl'], $login['accessToken']);

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
