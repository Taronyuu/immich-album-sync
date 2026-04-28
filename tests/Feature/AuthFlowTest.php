<?php

namespace Tests\Feature;

use App\Models\User;
use App\Sync\ImmichPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_provisions_user_and_api_key(): void
    {
        Http::fake([
            'https://immich.example.com/api/auth/login' => Http::response([
                'accessToken' => 'session-token-abc',
                'userId' => 'user-uuid-1',
                'userEmail' => 'alice@example.com',
                'name' => 'Alice',
                'profileImagePath' => '',
                'isAdmin' => false,
                'shouldChangePassword' => false,
                'isOnboarded' => true,
            ]),
            'https://immich.example.com/api/api-keys' => Http::response([
                'secret' => 'sync-api-key-secret',
                'apiKey' => [
                    'id' => 'api-key-uuid-1',
                    'name' => ImmichPermissions::AUTO_PROVISION_KEY_NAME,
                    'permissions' => ImmichPermissions::SYNC_SCOPES,
                    'createdAt' => now()->toIso8601String(),
                    'updatedAt' => now()->toIso8601String(),
                ],
            ]),
        ]);

        $ok = Auth::attempt([
            'immich_base_url' => 'https://immich.example.com',
            'email' => 'alice@example.com',
            'password' => 'correct-horse',
        ]);

        $this->assertTrue($ok);

        $user = User::query()->firstOrFail();
        $this->assertSame('https://immich.example.com', $user->immich_base_url);
        $this->assertSame('user-uuid-1', $user->immich_user_id);
        $this->assertSame('alice@example.com', $user->immich_email);
        $this->assertSame('api-key-uuid-1', $user->immich_api_key_id);
        $this->assertSame('sync-api-key-secret', Crypt::decryptString($user->immich_api_key_encrypted));
    }

    public function test_failed_login_returns_null_and_creates_no_user(): void
    {
        Http::fake([
            'https://immich.example.com/*' => Http::response(['message' => 'Invalid credentials'], 401),
        ]);

        $ok = Auth::attempt([
            'immich_base_url' => 'https://immich.example.com',
            'email' => 'alice@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertFalse($ok);
        $this->assertSame(0, User::query()->count());
    }

    public function test_second_login_does_not_provision_another_api_key(): void
    {
        Http::fake([
            'https://immich.example.com/api/auth/login' => Http::response([
                'accessToken' => 'session-token',
                'userId' => 'user-uuid-1',
                'userEmail' => 'alice@example.com',
                'name' => 'Alice',
                'profileImagePath' => '',
                'isAdmin' => false,
                'shouldChangePassword' => false,
                'isOnboarded' => true,
            ]),
            'https://immich.example.com/api/api-keys' => Http::response([
                'secret' => 'sync-api-key-secret',
                'apiKey' => ['id' => 'api-key-uuid-1', 'name' => 'x', 'permissions' => [], 'createdAt' => '', 'updatedAt' => ''],
            ]),
        ]);

        Auth::attempt(['immich_base_url' => 'https://immich.example.com', 'email' => 'alice@example.com', 'password' => 'p']);
        Auth::logout();

        Http::fake([
            'https://immich.example.com/api/auth/login' => Http::response([
                'accessToken' => 'session-token-2',
                'userId' => 'user-uuid-1',
                'userEmail' => 'alice@example.com',
                'name' => 'Alice',
                'profileImagePath' => '',
                'isAdmin' => false,
                'shouldChangePassword' => false,
                'isOnboarded' => true,
            ]),
            'https://immich.example.com/api/api-keys' => Http::response([], 500),
        ]);

        $ok = Auth::attempt(['immich_base_url' => 'https://immich.example.com', 'email' => 'alice@example.com', 'password' => 'p']);

        $this->assertTrue($ok);
        $this->assertSame(1, User::query()->count());
    }
}
