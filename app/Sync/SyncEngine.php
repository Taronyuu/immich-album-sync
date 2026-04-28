<?php

namespace App\Sync;

use App\Models\Album;
use App\Models\JobRun;
use App\Models\Mapping;
use App\Sync\DTO\RemoteAsset;
use App\Sync\Sources\SourceBackend;
use App\Sync\Sources\SourceFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;

class SyncEngine
{
    public function __construct(
        private readonly SourceFactory $sources,
    ) {}

    public function run(Album $album, JobRun $run): SyncReport
    {
        $logger = new RunLogger($run);

        $run->update([
            'status' => JobRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?? now(),
            'error_message' => null,
        ]);

        $album->update(['last_status' => 'running', 'last_error' => null]);

        $report = new SyncReport();

        try {
            $logger->info("Sync started for album «{$album->name}»", [
                'source_type' => $album->source_type,
                'source_base_url' => $album->source_base_url,
            ]);

            $this->executeSync($album, $run, $logger, $report);

            $run->update([
                'status' => JobRun::STATUS_SUCCEEDED,
                'finished_at' => now(),
                'uploaded_count' => $report->uploaded,
                'deduped_count' => $report->deduped,
                'removed_count' => $report->removed,
                'failed_count' => $report->failed,
            ]);

            $album->update([
                'last_status' => 'ok',
                'last_synced_at' => now(),
                'last_error' => null,
            ]);

            $logger->info('Sync finished', ['summary' => $report->summary()]);
            $logger->flush();

            return $report;
        } catch (\Throwable $e) {
            $logger->error('Sync failed: ' . $e->getMessage());
            $logger->flush();

            $run->update([
                'status' => JobRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => substr($e->getMessage(), 0, 1000),
                'uploaded_count' => $report->uploaded,
                'deduped_count' => $report->deduped,
                'removed_count' => $report->removed,
                'failed_count' => $report->failed,
            ]);

            $album->update([
                'last_status' => 'error',
                'last_error' => substr($e->getMessage(), 0, 1000),
            ]);

            throw $e;
        }
    }

    private function executeSync(Album $album, JobRun $run, RunLogger $logger, SyncReport $report): void
    {
        $source = $this->sources->for($album);
        $target = ImmichClient::withApiKey($album->user->immich_base_url, $album->user->immich_api_key);

        $albumId = $this->ensureAlbum($album, $target, $logger);

        $logger->info('Listing assets from source...');
        $remoteAssets = [];
        foreach ($source->listAssets() as $asset) {
            $remoteAssets[$asset->remoteId] = $asset;
        }
        $logger->info('Source returned ' . count($remoteAssets) . ' assets');

        $existingMappings = Mapping::query()
            ->where('album_id', $album->id)
            ->get()
            ->keyBy('remote_id');

        $newAssets = collect($remoteAssets)
            ->reject(fn (RemoteAsset $a) => $existingMappings->has($a->remoteId))
            ->values()
            ->all();

        $assetIdsToAdd = $existingMappings
            ->filter(fn (Mapping $m) => isset($remoteAssets[$m->remote_id]))
            ->pluck('local_asset_id')
            ->all();

        $logger->info(count($newAssets) . ' new asset(s), ' . count($existingMappings) . ' already mapped');

        if (! empty($newAssets)) {
            $assetIdsToAdd = array_merge(
                $assetIdsToAdd,
                $this->importNewAssets($album, $run, $logger, $source, $target, $newAssets, $report),
            );
        }

        if (! empty($assetIdsToAdd)) {
            $logger->info('Adding ' . count($assetIdsToAdd) . ' asset(s) to target album');
            $this->ensureAlbumMembership($target, $albumId, array_values(array_unique($assetIdsToAdd)));
        }

        $this->applyDeletePolicy($album, $logger, $target, $albumId, $existingMappings, $remoteAssets, $report);
        $this->persistRunCounts($run, $report);
    }

    private function ensureAlbum(Album $album, ImmichClient $target, RunLogger $logger): string
    {
        if ($album->target_album_id) {
            try {
                $target->getAlbum($album->target_album_id);

                return $album->target_album_id;
            } catch (RequestException $e) {
                $logger->warn('Target album disappeared, recreating', [
                    'old_target_album_id' => $album->target_album_id,
                ]);
            }
        }

        $logger->info("Creating target album «{$album->target_album_name}»");
        $remote = $target->createAlbum($album->target_album_name);
        $album->update(['target_album_id' => $remote['id']]);

        return $remote['id'];
    }

    /**
     * @param  array<int, RemoteAsset>  $newAssets
     * @return array<int, string>  local asset IDs to add to the album
     */
    private function importNewAssets(Album $album, JobRun $run, RunLogger $logger, SourceBackend $source, ImmichClient $target, array $newAssets, SyncReport $report): array
    {
        $localIds = [];

        $bulkPayload = [];
        foreach ($newAssets as $asset) {
            if ($asset->checksum) {
                $bulkPayload[] = ['id' => $asset->remoteId, 'checksum' => $asset->checksum];
            }
        }

        $alreadyOnTarget = [];
        if (! empty($bulkPayload)) {
            $check = $target->bulkUploadCheck($bulkPayload);
            foreach (($check['results'] ?? []) as $result) {
                if (($result['action'] ?? null) === 'reject' && ($result['reason'] ?? null) === 'duplicate') {
                    $alreadyOnTarget[$result['id']] = $result['assetId'] ?? null;
                }
            }
            if (! empty($alreadyOnTarget)) {
                $logger->info(count($alreadyOnTarget) . ' asset(s) already exist on target — linking instead of uploading');
            }
        }

        foreach ($newAssets as $i => $asset) {
            try {
                if (isset($alreadyOnTarget[$asset->remoteId]) && $alreadyOnTarget[$asset->remoteId] !== null) {
                    $localId = $alreadyOnTarget[$asset->remoteId];
                    $this->recordMapping($album, $asset, $localId);
                    $localIds[] = $localId;
                    $report->deduped++;

                    continue;
                }

                $logger->info('Uploading: ' . $asset->filename, ['remote_id' => $asset->remoteId]);
                $localId = $this->downloadAndUpload($album, $source, $target, $asset);

                if ($localId !== null) {
                    $this->recordMapping($album, $asset, $localId);
                    $localIds[] = $localId;
                    $report->uploaded++;
                }
            } catch (\Throwable $e) {
                $logger->error('Failed to import ' . $asset->filename . ': ' . $e->getMessage(), [
                    'remote_id' => $asset->remoteId,
                ]);
                $report->failed++;
            }

            if (($i + 1) % 5 === 0) {
                $this->persistRunCounts($run, $report);
            }
        }

        return $localIds;
    }

    private function downloadAndUpload(Album $album, SourceBackend $source, ImmichClient $target, RemoteAsset $asset): ?string
    {
        $tempPath = $source->downloadAsset($asset->remoteId);

        try {
            $checksum = $asset->checksum ?? base64_encode(hash_file('sha1', $tempPath, binary: true));
            $createdAt = ($asset->takenAt ?? now())->toIso8601String();
            $modifiedAt = ($asset->modifiedAt ?? $asset->takenAt ?? now())->toIso8601String();

            $response = $target->uploadAsset(
                filePath: $tempPath,
                filename: $asset->filename,
                checksumSha1: $checksum,
                fileCreatedAt: $createdAt,
                fileModifiedAt: $modifiedAt,
                deviceAssetId: 'imferry-' . $album->id . '-' . $asset->remoteId,
                deviceId: 'imferry',
            );

            return $response['id'] ?? null;
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private function recordMapping(Album $album, RemoteAsset $asset, string $localAssetId): void
    {
        DB::transaction(function () use ($album, $asset, $localAssetId): void {
            Mapping::query()->updateOrInsert(
                ['album_id' => $album->id, 'remote_id' => $asset->remoteId],
                [
                    'remote_checksum' => $asset->checksum,
                    'local_asset_id' => $localAssetId,
                    'imported_at' => now(),
                ],
            );
        });
    }

    private function ensureAlbumMembership(ImmichClient $target, string $albumId, array $assetIds): void
    {
        foreach (array_chunk($assetIds, 200) as $chunk) {
            $target->addAssetsToAlbum($albumId, $chunk);
        }
    }

    /**
     * @param  iterable<string, Mapping>  $existingMappings
     * @param  array<string, RemoteAsset>  $remoteAssets
     */
    private function applyDeletePolicy(Album $album, RunLogger $logger, ImmichClient $target, string $albumId, $existingMappings, array $remoteAssets, SyncReport $report): void
    {
        $orphans = collect($existingMappings)->filter(
            fn (Mapping $m) => ! isset($remoteAssets[$m->remote_id]),
        );

        if ($orphans->isEmpty() || $album->on_remote_delete === 'ignore') {
            return;
        }

        $localIds = $orphans->pluck('local_asset_id')->all();

        $logger->info('Removing ' . count($localIds) . " orphaned asset(s); policy: {$album->on_remote_delete}");

        if ($album->on_remote_delete === 'remove-from-album') {
            foreach (array_chunk($localIds, 200) as $chunk) {
                $target->removeAssetsFromAlbum($albumId, $chunk);
            }
        } elseif ($album->on_remote_delete === 'trash') {
            foreach (array_chunk($localIds, 200) as $chunk) {
                $target->trashAssets($chunk);
            }
        }

        Mapping::query()
            ->where('album_id', $album->id)
            ->whereIn('remote_id', $orphans->pluck('remote_id')->all())
            ->delete();

        $report->removed = $orphans->count();
    }

    private function persistRunCounts(JobRun $run, SyncReport $report): void
    {
        $run->update([
            'uploaded_count' => $report->uploaded,
            'deduped_count' => $report->deduped,
            'removed_count' => $report->removed,
            'failed_count' => $report->failed,
        ]);
    }
}
