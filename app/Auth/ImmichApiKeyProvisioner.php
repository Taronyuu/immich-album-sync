<?php

namespace App\Auth;

use App\Sync\ImmichClient;
use App\Sync\ImmichPermissions;

class ImmichApiKeyProvisioner
{
    public function provision(string $baseUrl, string $accessToken): array
    {
        $response = ImmichClient::withBearerToken($baseUrl, $accessToken)->createApiKey(
            ImmichPermissions::AUTO_PROVISION_KEY_NAME,
            ImmichPermissions::SYNC_SCOPES,
        );

        return [
            'id' => $response['apiKey']['id'],
            'secret' => $response['secret'],
        ];
    }
}
