<?php

namespace Tests\Feature;

use App\Services\Exceptions\RemoteImmichConnectException;
use App\Services\RemoteImmichConnector;
use App\Sync\ImmichPermissions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemoteImmichConnectorTest extends TestCase
{
    public function test_connect_returns_full_dto_on_valid_credentials(): void
    {
        Http::fake([
            'https://remote.example.com/api/auth/login' => Http::response([
                'accessToken' => 'session-abc',
                'userId' => 'remote-user-uuid',
                'userEmail' => 'bob@example.com',
                'name' => 'Bob',
                'isAdmin' => false,
            ]),
            'https://remote.example.com/api/api-keys' => Http::response([
                'secret' => 'remote-key-secret',
                'apiKey' => [
                    'id' => 'remote-key-uuid',
                    'name' => ImmichPermissions::AUTO_PROVISION_KEY_NAME,
                    'permissions' => ImmichPermissions::SYNC_SCOPES,
                ],
            ]),
        ]);

        $connection = (new RemoteImmichConnector())->connect(
            baseUrl: 'https://remote.example.com',
            email: 'bob@example.com',
            password: 'hunter2',
        );

        $this->assertSame('https://remote.example.com', $connection->baseUrl);
        $this->assertSame('remote-user-uuid', $connection->immichUserId);
        $this->assertSame('bob@example.com', $connection->email);
        $this->assertSame('Bob', $connection->name);
        $this->assertFalse($connection->isAdmin);
        $this->assertSame('remote-key-uuid', $connection->apiKeyId);
        $this->assertSame('remote-key-secret', $connection->apiKeySecret);
    }

    public function test_connect_normalizes_trailing_slash_in_base_url(): void
    {
        Http::fake([
            'https://remote.example.com/api/auth/login' => Http::response([
                'accessToken' => 't', 'userId' => 'u', 'userEmail' => 'e@x', 'name' => null, 'isAdmin' => false,
            ]),
            'https://remote.example.com/api/api-keys' => Http::response([
                'secret' => 's', 'apiKey' => ['id' => 'k', 'name' => 'n', 'permissions' => []],
            ]),
        ]);

        $connection = (new RemoteImmichConnector())->connect(
            baseUrl: 'https://remote.example.com/',
            email: 'e@x',
            password: 'p',
        );

        $this->assertSame('https://remote.example.com', $connection->baseUrl);
    }

    public function test_connect_throws_when_credentials_are_blank(): void
    {
        $this->expectException(RemoteImmichConnectException::class);

        (new RemoteImmichConnector())->connect('https://remote.example.com', '', 'p');
    }

    public function test_connect_throws_on_login_http_failure(): void
    {
        Http::fake([
            'https://remote.example.com/*' => Http::response(['message' => 'Invalid credentials'], 401),
        ]);

        $this->expectException(RemoteImmichConnectException::class);

        (new RemoteImmichConnector())->connect('https://remote.example.com', 'bob@example.com', 'wrong');
    }

    public function test_connect_throws_when_login_response_missing_token(): void
    {
        Http::fake([
            'https://remote.example.com/api/auth/login' => Http::response(['userId' => 'u']),
        ]);

        $this->expectException(RemoteImmichConnectException::class);

        (new RemoteImmichConnector())->connect('https://remote.example.com', 'bob@example.com', 'p');
    }

    public function test_login_does_not_call_api_keys_endpoint(): void
    {
        Http::fake([
            'https://remote.example.com/api/auth/login' => Http::response([
                'accessToken' => 't',
                'userId' => 'u',
                'userEmail' => 'e@x',
                'name' => null,
                'isAdmin' => false,
            ]),
        ]);

        $payload = (new RemoteImmichConnector())->login('https://remote.example.com', 'e@x', 'p');

        $this->assertSame('https://remote.example.com', $payload['baseUrl']);
        $this->assertSame('t', $payload['accessToken']);
        Http::assertSentCount(1);
    }
}
