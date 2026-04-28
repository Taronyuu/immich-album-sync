<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\JobRun;
use App\Models\User;
use App\Sync\SyncEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JobRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_engine_creates_succeeded_run_with_log_and_counts(): void
    {
        $user = $this->makeUser();
        $album = $this->makeAlbumWith($user);

        $this->mockImmichResponses(assetCount: 3);

        $run = JobRun::create([
            'album_id' => $album->id,
            'status' => JobRun::STATUS_RUNNING,
            'trigger' => JobRun::TRIGGER_MANUAL,
            'started_at' => now(),
        ]);

        app(SyncEngine::class)->run($album->load('user'), $run);

        $run->refresh();
        $this->assertSame(JobRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(3, $run->uploaded_count);
        $this->assertSame(0, $run->failed_count);
        $this->assertNotNull($run->finished_at);
        $this->assertNotEmpty($run->log);
        $this->assertStringContainsString('Sync started', $run->log);
        $this->assertStringContainsString('Sync finished', $run->log);
    }

    public function test_engine_marks_run_failed_when_source_throws(): void
    {
        $user = $this->makeUser();
        $album = $this->makeAlbumWith($user);

        Http::fake([
            'https://demo.immich.app/api/shared-links/login' => Http::response(['ok' => true]),
            'https://demo.immich.app/api/shared-links/me' => Http::response(['message' => 'Invalid'], 401),
            'https://target.example.com/*' => Http::response(['id' => 'target-album-id'], 200),
        ]);

        $run = JobRun::create([
            'album_id' => $album->id,
            'status' => JobRun::STATUS_RUNNING,
            'trigger' => JobRun::TRIGGER_MANUAL,
            'started_at' => now(),
        ]);

        try {
            app(SyncEngine::class)->run($album->load('user'), $run);
        } catch (\Throwable) {
            // Engine rethrows so queue worker can mark failed; we only care about the JobRun state.
        }

        $run->refresh();
        $this->assertSame(JobRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->error_message);
        $this->assertNotNull($run->finished_at);
        $this->assertStringContainsString('Sync failed', $run->log);
    }

    private function makeUser(): User
    {
        return User::create([
            'immich_base_url' => 'https://target.example.com',
            'immich_user_id' => 'user-1',
            'immich_email' => 'alice@example.com',
            'immich_user_name' => 'Alice',
            'immich_api_key_id' => 'key-1',
            'immich_api_key_encrypted' => Crypt::encryptString('target-api-key'),
        ]);
    }

    private function makeAlbumWith(User $user): Album
    {
        return Album::create([
            'user_id' => $user->id,
            'name' => 'Test Album',
            'schedule' => '*/15 * * * *',
            'source_type' => 'immich-shared-link',
            'source_base_url' => 'https://demo.immich.app',
            'source_share_key' => 'fake-share-key',
            'target_album_name' => 'Target Album',
            'on_remote_delete' => 'remove-from-album',
            'is_active' => true,
        ]);
    }

    private function mockImmichResponses(int $assetCount): void
    {
        $assets = [];
        for ($i = 1; $i <= $assetCount; $i++) {
            $assets[] = [
                'id' => "remote-{$i}",
                'originalFileName' => "photo-{$i}.jpg",
                'originalMimeType' => 'image/jpeg',
                'checksum' => "checksum-{$i}",
                'fileCreatedAt' => '2026-01-01T00:00:00Z',
                'fileModifiedAt' => '2026-01-01T00:00:00Z',
            ];
        }

        Http::fake([
            'https://demo.immich.app/api/shared-links/me' => Http::response([
                'type' => 'ALBUM',
                'allowDownload' => true,
                'album' => ['id' => 'source-album-id'],
            ]),
            'https://demo.immich.app/api/albums/source-album-id' => Http::response([
                'id' => 'source-album-id',
                'assets' => $assets,
            ]),
            'https://demo.immich.app/api/assets/*/original' => Http::response('FAKEFILECONTENT', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
            'https://target.example.com/api/albums' => Http::response(['id' => 'target-album-id', 'albumName' => 'Target Album']),
            'https://target.example.com/api/albums/target-album-id' => Http::response(['id' => 'target-album-id']),
            'https://target.example.com/api/albums/target-album-id/assets' => Http::response([]),
            'https://target.example.com/api/assets/bulk-upload-check' => Http::response(['results' => []]),
            'https://target.example.com/api/assets' => Http::sequence()
                ->push(['id' => 'local-1', 'status' => 'created'])
                ->push(['id' => 'local-2', 'status' => 'created'])
                ->push(['id' => 'local-3', 'status' => 'created']),
        ]);
    }
}
