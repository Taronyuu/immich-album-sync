<?php

namespace App\Auth;

use App\Sync\ImmichClient;
use App\Sync\ImmichPermissions;

class ImmichApiKeyProvisioner
{
    public function provisionWithBearerToken(string $baseUrl, string $accessToken): array
    {
        return $this->finishProvision(
            ImmichClient::withBearerToken($baseUrl, $accessToken)->createApiKey(
                ImmichPermissions::AUTO_PROVISION_KEY_NAME,
                ImmichPermissions::SYNC_SCOPES,
            ),
        );
    }

    public function provisionWithApiKey(string $baseUrl, string $apiKey): array
    {
        return $this->finishProvision(
            ImmichClient::withApiKey($baseUrl, $apiKey)->createApiKey(
                ImmichPermissions::AUTO_PROVISION_KEY_NAME,
                ImmichPermissions::SYNC_SCOPES,
            ),
        );
    }

    private function finishProvision(array $response): array
    {
        return [
            'id' => $response['apiKey']['id'],
            'secret' => $response['secret'],
        ];
    }
}
