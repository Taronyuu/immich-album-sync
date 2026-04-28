<?php

namespace Tests\Feature;

use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\JobRuns\JobRunResource;
use App\Models\Album;
use App\Models\JobRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class AlbumScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_albums_are_scoped_to_authenticated_user(): void
    {
        [$alice, $bob] = $this->makeUsers();

        $aliceAlbum = $this->makeAlbumFor($alice, 'Alice album');
        $bobAlbum = $this->makeAlbumFor($bob, 'Bob album');

        Auth::login($alice);
        $aliceVisible = AlbumResource::getEloquentQuery()->pluck('id')->all();
        $this->assertSame([$aliceAlbum->id], $aliceVisible);

        Auth::login($bob);
        $bobVisible = AlbumResource::getEloquentQuery()->pluck('id')->all();
        $this->assertSame([$bobAlbum->id], $bobVisible);
    }

    public function test_job_runs_are_scoped_through_album(): void
    {
        [$alice, $bob] = $this->makeUsers();

        $aliceAlbum = $this->makeAlbumFor($alice, 'A');
        $bobAlbum = $this->makeAlbumFor($bob, 'B');

        $aliceRun = JobRun::create(['album_id' => $aliceAlbum->id, 'status' => JobRun::STATUS_SUCCEEDED]);
        $bobRun = JobRun::create(['album_id' => $bobAlbum->id, 'status' => JobRun::STATUS_SUCCEEDED]);

        Auth::login($alice);
        $aliceVisible = JobRunResource::getEloquentQuery()->pluck('id')->all();
        $this->assertSame([$aliceRun->id], $aliceVisible);

        Auth::login($bob);
        $bobVisible = JobRunResource::getEloquentQuery()->pluck('id')->all();
        $this->assertSame([$bobRun->id], $bobVisible);
    }

    public function test_canview_returns_false_for_other_users_album(): void
    {
        [$alice, $bob] = $this->makeUsers();

        $bobAlbum = $this->makeAlbumFor($bob, 'B');

        Auth::login($alice);
        $this->assertFalse(AlbumResource::canView($bobAlbum));
    }

    /** @return array{0: User, 1: User} */
    private function makeUsers(): array
    {
        return [
            User::create([
                'immich_base_url' => 'https://immich.example.com',
                'immich_user_id' => 'user-alice',
                'immich_email' => 'alice@example.com',
                'immich_user_name' => 'Alice',
                'immich_api_key_id' => 'key-a',
                'immich_api_key_encrypted' => Crypt::encryptString('secret-a'),
            ]),
            User::create([
                'immich_base_url' => 'https://immich.example.com',
                'immich_user_id' => 'user-bob',
                'immich_email' => 'bob@example.com',
                'immich_user_name' => 'Bob',
                'immich_api_key_id' => 'key-b',
                'immich_api_key_encrypted' => Crypt::encryptString('secret-b'),
            ]),
        ];
    }

    private function makeAlbumFor(User $user, string $name): Album
    {
        return Album::create([
            'user_id' => $user->id,
            'name' => $name,
            'schedule' => '*/15 * * * *',
            'source_type' => 'immich-shared-link',
            'source_base_url' => 'https://demo.immich.app',
            'source_share_key' => 'fake',
            'target_album_name' => $name . ' target',
            'on_remote_delete' => 'remove-from-album',
            'is_active' => true,
        ]);
    }
}
