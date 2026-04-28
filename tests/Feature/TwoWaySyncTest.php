<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\JobRun;
use App\Models\Mapping;
use App\Models\User;
use App\Sync\SyncEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TwoWaySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_push_pass_uploads_local_assets_without_mapping(): void
    {
        $album = $this->makeApiKeyAlbum(direction: 'push');
        $this->mockTargetAlbumWithAssets(['local-1', 'local-2']);
        $this->mockSourceUploads([
            'bulk_upload_check_results' => [],
            'upload_sequence' => ['source-asset-1', 'source-asset-2'],
        ]);

        $run = $this->makeRun($album);

        app(SyncEngine::class)->run($album->load('user'), $run);

        $run->refresh();
        $this->assertSame(JobRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(2, $run->pushed_count);
        $this->assertSame(0, $run->pushed_deduped_count);
        $this->assertSame(0, $run->pushed_failed_count);
        $this->assertSame(0, $run->uploaded_count);

        $this->assertSame(2, Mapping::query()->where('album_id', $album->id)->count());

        Http::assertSent(fn ($r) => str_contains($r->url(), 'source.example.com/api/albums/source-album-1/assets')
            && $r->method() === 'PUT');
    }

    public function test_push_pass_skips_already_mapped_local_assets(): void
    {
        $album = $this->makeApiKeyAlbum(direction: 'push');

        Mapping::query()->insert([
            ['album_id' => $album->id, 'remote_id' => 'pre-source-1', 'local_asset_id' => 'local-1', 'remote_checksum' => null, 'imported_at' => now()],
            ['album_id' => $album->id, 'remote_id' => 'pre-source-2', 'local_asset_id' => 'local-2', 'remote_checksum' => null, 'imported_at' => now()],
        ]);

        $this->mockTargetAlbumWithAssets(['local-1', 'local-2']);
        $this->mockSourceUploads([
            'bulk_upload_check_results' => [],
            'upload_sequence' => [],
        ]);

        $run = $this->makeRun($album);

        app(SyncEngine::class)->run($album->load('user'), $run);

        $run->refresh();
        $this->assertSame(0, $run->pushed_count);
        $this->assertSame(0, $run->pushed_deduped_count);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'source.example.com/api/assets')
            && $r->method() === 'POST'
            && ! str_contains($r->url(), 'bulk-upload-check'));
    }

    public function test_push_pass_dedups_via_bulk_upload_check(): void
    {
        $album = $this->makeApiKeyAlbum(direction: 'push');

        $this->mockTargetAlbumWithAssets(['local-1', 'local-2']);
        $this->mockSourceUploads([
            'bulk_upload_check_results' => [
                ['id' => 'local-1', 'action' => 'reject', 'reason' => 'duplicate', 'assetId' => 'existing-source-1'],
            ],
            'upload_sequence' => ['source-asset-2'],
        ]);

        $run = $this->makeRun($album);

        app(SyncEngine::class)->run($album->load('user'), $run);

        $run->refresh();
        $this->assertSame(1, $run->pushed_count);
        $this->assertSame(1, $run->pushed_deduped_count);
        $this->assertSame(2, Mapping::query()->where('album_id', $album->id)->count());
    }

    public function test_push_pass_skipped_when_direction_is_pull(): void
    {
        $album = $this->makeApiKeyAlbum(direction: 'pull');

        $this->mockEmptySourceList();
        $this->mockTargetAlbumWithAssets(['local-1']);

        $run = $this->makeRun($album);

        app(SyncEngine::class)->run($album->load('user'), $run);

        $run->refresh();
        $this->assertSame(JobRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(0, $run->pushed_count);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'source.example.com/api/assets')
            && $r->method() === 'POST');
    }

    public function test_push_pass_skipped_for_read_only_shared_link_source(): void
    {
        $album = $this->makeSharedLinkAlbum(direction: 'both');

        Http::fake([
            'https://demo.immich.app/api/shared-links/me' => Http::response([
                'type' => 'ALBUM', 'allowDownload' => true, 'album' => ['id' => 'src-1'],
            ]),
            'https://demo.immich.app/api/albums/src-1' => Http::response(['id' => 'src-1', 'assets' => []]),
            'https://target.example.com/api/albums/target-album-1' => Http::response([
                'id' => 'target-album-1',
                'assets' => [
                    ['id' => 'local-1', 'originalFileName' => 'a.jpg', 'checksum' => 'sum-1', 'fileCreatedAt' => '2026-01-01T00:00:00Z', 'fileModifiedAt' => '2026-01-01T00:00:00Z'],
                ],
            ]),
        ]);

        $run = $this->makeRun($album);

        app(SyncEngine::class)->run($album->load('user'), $run);

        $run->refresh();
        $this->assertSame(JobRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(0, $run->pushed_count);
        $this->assertStringContainsString('Push direction requested but source is read-only', $run->log);
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

    private function makeApiKeyAlbum(string $direction): Album
    {
        $user = $this->makeUser();

        return Album::create([
            'user_id' => $user->id,
            'name' => 'Two-way Album',
            'schedule' => '*/15 * * * *',
            'source_type' => Album::SOURCE_API_KEY,
            'direction' => $direction,
            'source_base_url' => 'https://source.example.com',
            'source_api_key' => 'source-api-key',
            'source_album_id' => 'source-album-1',
            'target_album_name' => 'Mirror',
            'target_album_id' => 'target-album-1',
            'on_remote_delete' => 'ignore',
            'is_active' => true,
        ]);
    }

    private function makeSharedLinkAlbum(string $direction): Album
    {
        $user = $this->makeUser();

        return Album::create([
            'user_id' => $user->id,
            'name' => 'Shared Link Album',
            'schedule' => '*/15 * * * *',
            'source_type' => Album::SOURCE_SHARED_LINK,
            'direction' => $direction,
            'source_base_url' => 'https://demo.immich.app',
            'source_share_key' => 'fake-share-key',
            'target_album_name' => 'Mirror',
            'target_album_id' => 'target-album-1',
            'on_remote_delete' => 'ignore',
            'is_active' => true,
        ]);
    }

    private function makeRun(Album $album): JobRun
    {
        return JobRun::create([
            'album_id' => $album->id,
            'status' => JobRun::STATUS_RUNNING,
            'trigger' => JobRun::TRIGGER_MANUAL,
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array<int, string>  $localAssetIds
     */
    private function mockTargetAlbumWithAssets(array $localAssetIds): void
    {
        $assets = [];
        foreach ($localAssetIds as $i => $id) {
            $assets[] = [
                'id' => $id,
                'originalFileName' => "{$id}.jpg",
                'checksum' => "checksum-{$id}",
                'fileCreatedAt' => '2026-01-01T00:00:00Z',
                'fileModifiedAt' => '2026-01-01T00:00:00Z',
            ];
        }

        Http::fake([
            'https://target.example.com/api/albums/target-album-1' => Http::response([
                'id' => 'target-album-1',
                'assets' => $assets,
            ]),
            'https://target.example.com/api/assets/*/original' => Http::response('FAKEBYTES', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
            'https://target.example.com/api/albums/target-album-1/assets' => Http::response([]),
        ]);
    }

    /**
     * @param  array{bulk_upload_check_results: array<int, array<string, mixed>>, upload_sequence: array<int, string>}  $opts
     */
    private function mockSourceUploads(array $opts): void
    {
        $assetsResponse = Http::response([], 500);
        if (! empty($opts['upload_sequence'])) {
            $sequence = Http::sequence();
            foreach ($opts['upload_sequence'] as $sourceId) {
                $sequence->push(['id' => $sourceId, 'status' => 'created']);
            }
            $assetsResponse = $sequence;
        }

        Http::fake([
            'https://source.example.com/api/albums/source-album-1' => Http::response(['id' => 'source-album-1', 'assets' => []]),
            'https://source.example.com/api/assets/bulk-upload-check' => Http::response([
                'results' => $opts['bulk_upload_check_results'],
            ]),
            'https://source.example.com/api/assets' => $assetsResponse,
            'https://source.example.com/api/albums/source-album-1/assets' => Http::response([]),
        ]);
    }

    private function mockEmptySourceList(): void
    {
        Http::fake([
            'https://source.example.com/api/albums/source-album-1' => Http::response([
                'id' => 'source-album-1', 'assets' => [],
            ]),
            'https://source.example.com/api/assets/bulk-upload-check' => Http::response(['results' => []]),
            'https://source.example.com/api/albums/source-album-1/assets' => Http::response([]),
            'https://source.example.com/api/assets' => Http::response([], 500),
        ]);
    }
}
