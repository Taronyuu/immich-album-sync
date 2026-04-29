<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\ImmichLogin;
use App\Models\User;
use App\Sync\ImmichPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class LoginFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_form_renders_all_four_fields(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('immich_base_url', false)
            ->assertSee('immich_api_key', false)
            ->assertSee('Immich URL')
            ->assertSee('Or sign in with an Immich API key');
    }

    public function test_url_field_pre_populates_from_remembered_cookie(): void
    {
        $this->withUnencryptedCookie('ias_last_immich_url', 'https://my-remembered-immich.example.com')
            ->get('/login')
            ->assertOk()
            ->assertSee('my-remembered-immich.example.com', false);
    }

    public function test_authenticate_with_password_succeeds_and_queues_url_cookie(): void
    {
        Http::fake([
            'https://immich.example.com/api/auth/login' => Http::response([
                'accessToken' => 'tok',
                'userId' => 'uid-1',
                'userEmail' => 'a@example.com',
                'name' => 'Alice',
                'isAdmin' => false,
            ]),
            'https://immich.example.com/api/api-keys' => Http::response([
                'secret' => 'sync-secret',
                'apiKey' => [
                    'id' => 'sync-id',
                    'name' => ImmichPermissions::AUTO_PROVISION_KEY_NAME,
                    'permissions' => ImmichPermissions::SYNC_SCOPES,
                    'createdAt' => '',
                    'updatedAt' => '',
                ],
            ]),
        ]);

        $component = Livewire::test(ImmichLogin::class)
            ->set('data.immich_base_url', 'https://immich.example.com')
            ->set('data.email', 'a@example.com')
            ->set('data.password', 'pw')
            ->call('authenticate');

        $component->assertHasNoErrors();
        $this->assertTrue(auth()->check());

        $queued = Cookie::getQueuedCookies();
        $names = array_map(fn ($c) => $c->getName(), $queued);
        $this->assertContains('ias_last_immich_url', $names, 'URL cookie should be queued after login');

        $cookie = collect($queued)->first(fn ($c) => $c->getName() === 'ias_last_immich_url');
        $this->assertSame('https://immich.example.com', $cookie->getValue());
    }

    public function test_authenticate_with_unreachable_url_renders_form_error(): void
    {
        Http::fake([
            'http://immich-server/*' => fn () => throw new ConnectionException('cURL error 7'),
        ]);

        Livewire::test(ImmichLogin::class)
            ->set('data.immich_base_url', 'http://immich-server')
            ->set('data.email', 'a@example.com')
            ->set('data.password', 'pw')
            ->call('authenticate')
            ->assertHasErrors('data.immich_base_url');

        $this->assertFalse(auth()->check());
    }

    public function test_authenticate_with_api_key_only_succeeds_without_password(): void
    {
        Http::fake([
            'https://immich.example.com/api/users/me' => Http::response([
                'id' => 'oidc-uid',
                'email' => 'oidc@example.com',
                'name' => 'OIDC',
                'isAdmin' => false,
            ]),
            'https://immich.example.com/api/api-keys' => Http::response([
                'secret' => 'provisioned',
                'apiKey' => [
                    'id' => 'provisioned-id',
                    'name' => ImmichPermissions::AUTO_PROVISION_KEY_NAME,
                    'permissions' => ImmichPermissions::SYNC_SCOPES,
                    'createdAt' => '',
                    'updatedAt' => '',
                ],
            ]),
        ]);

        Livewire::test(ImmichLogin::class)
            ->set('data.immich_base_url', 'https://immich.example.com')
            ->set('data.email', 'oidc@example.com')
            ->set('data.password', '')
            ->set('data.immich_api_key', 'bootstrap-key')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertTrue(auth()->check());

        $user = User::query()->firstOrFail();
        $this->assertSame('oidc-uid', $user->immich_user_id);
        $this->assertSame('provisioned', Crypt::decryptString($user->immich_api_key_encrypted));
    }

    public function test_authenticate_with_invalid_api_key_shows_generic_failure(): void
    {
        Http::fake([
            'https://immich.example.com/api/users/me' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        Livewire::test(ImmichLogin::class)
            ->set('data.immich_base_url', 'https://immich.example.com')
            ->set('data.email', 'oidc@example.com')
            ->set('data.password', '')
            ->set('data.immich_api_key', 'bogus-key')
            ->call('authenticate')
            ->assertHasErrors('data.email');

        $this->assertFalse(auth()->check());
    }

    public function test_authenticate_password_required_when_no_api_key(): void
    {
        Livewire::test(ImmichLogin::class)
            ->set('data.immich_base_url', 'https://immich.example.com')
            ->set('data.email', 'a@example.com')
            ->set('data.password', '')
            ->call('authenticate')
            ->assertHasErrors(['data.password']);

        $this->assertFalse(auth()->check());
    }
}
