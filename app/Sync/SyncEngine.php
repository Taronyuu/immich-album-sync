<?php

namespace App\Sync;

use App\Models\Album;
use App\Models\JobRun;
use App\Models\Mapping;
use App\Sync\DTO\RemoteAsset;
use App\Sync\Sources\SourceBackend;
use App\Sync\Sources\SourceFactory;
use App\Sync\Sources\WritableSourceBackend;
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
                'direction' => $album->direction ?? Album::DIRECTION_PULL,
            ]);

            $this->executeSync($album, $run, $logger, $report);

            $this->persistRunCounts($run, $report);
            $run->update([
                'status' => JobRun::STATUS_SUCCEEDED,
                'finished_at' => now(),
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

            $this->persistRunCounts($run, $report);
            $run->update([
                'status' => JobRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => substr($e->getMessage(), 0, 1000),
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

        $direction = $album->direction ?: Album::DIRECTION_PULL;

        if (in_array($direction, [Album::DIRECTION_PULL, Album::DIRECTION_BOTH], true)) {
            $this->executePullPass($album, $run, $logger, $source, $target, $albumId, $report);
        }

        if (in_array($direction, [Album::DIRECTION_PUSH, Album::DIRECTION_BOTH], true)) {
            if ($source instanceof WritableSourceBackend) {
                $this->executePushPass($album, $run, $logger, $source, $target, $albumId, $report);
            } else {
                $logger->warn('Push direction requested but source is read-only; skipping push pass');
            }
        }
    }

    private function executePullPass(Album $album, JobRun $run, RunLogger $logger, SourceBackend $source, ImmichClient $target, string $albumId, SyncReport $report): void
    {
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
    }

    private function executePushPass(Album $album, JobRun $run, RunLogger $logger, WritableSourceBackend $source, ImmichClient $target, string $targetAlbumId, SyncReport $report): void
    {
        $logger->info('Push pass: listing target album assets');

        $targetAlbum = $target->getAlbum($targetAlbumId);
        $localAssets = $targetAlbum['assets'] ?? [];
        $logger->info('Target album has ' . count($localAssets) . ' asset(s)');

        $existingLocalIds = Mapping::query()
            ->where('album_id', $album->id)
            ->pluck('local_asset_id')
            ->flip();

        $candidates = collect($localAssets)
            ->reject(fn (array $a) => isset($existingLocalIds[$a['id'] ?? '']))
            ->values()
            ->all();

        $logger->info(count($candidates) . ' local asset(s) eligible to push');

        if (empty($candidates)) {
            return;
        }

        $bulkPayload = [];
        foreach ($candidates as $asset) {
            if (! empty($asset['checksum']) && ! empty($asset['id'])) {
                $bulkPayload[] = ['id' => $asset['id'], 'checksum' => $asset['checksum']];
            }
        }

        $alreadyOnSource = [];
        $trashedOnSource = [];
        if (! empty($bulkPayload)) {
            $check = $source->bulkUploadCheck($bulkPayload);
            foreach (($check['results'] ?? []) as $result) {
                if (($result['action'] ?? null) === 'reject' && ($result['reason'] ?? null) === 'duplicate') {
                    $assetId = $result['assetId'] ?? null;
                    if ($assetId === null) {
                        continue;
                    }
                    if (($result['isTrashed'] ?? false) === true) {
                        $trashedOnSource[$result['id']] = $assetId;
                    } else {
                        $alreadyOnSource[$result['id']] = $assetId;
                    }
                }
            }

            if (! empty($trashedOnSource)) {
                $logger->info(count($trashedOnSource) . ' asset(s) exist on source but are trashed — attempting restore');
                $restored = $source->restoreAssetsFromTrash(array_values($trashedOnSource));
                if ($restored) {
                    foreach ($trashedOnSource as $localId => $sourceId) {
                        $alreadyOnSource[$localId] = $sourceId;
                    }
                    $logger->info('Restored ' . count($trashedOnSource) . ' trashed asset(s) on source; linking them');
                } else {
                    $logger->info('No permission to restore from trash on source (asset.delete scope missing); will re-upload');
                }
            }

            if (! empty($alreadyOnSource)) {
                $logger->info(count($alreadyOnSource) . ' asset(s) already exist on source — linking only');
            }
        }

        $sourceIdsToAddToAlbum = [];

        foreach ($candidates as $i => $asset) {
            $localId = $asset['id'] ?? null;
            if ($localId === null) {
                $report->pushedFailed++;
                continue;
            }

            try {
                $checksum = $asset['checksum'] ?? null;

                if (isset($alreadyOnSource[$localId]) && $alreadyOnSource[$localId] !== null) {
                    $sourceId = $alreadyOnSource[$localId];
                    $this->upsertMapping($album->id, $sourceId, $checksum, $localId);
                    $sourceIdsToAddToAlbum[] = $sourceId;
                    $report->pushedDeduped++;

                    continue;
                }

                $logger->info('Pushing: ' . ($asset['originalFileName'] ?? $localId), ['local_asset_id' => $localId]);

                $sourceId = $this->downloadFromTargetAndUploadToSource($album, $target, $source, $asset);

                if ($sourceId !== null) {
                    $this->upsertMapping($album->id, $sourceId, $checksum, $localId);
                    $sourceIdsToAddToAlbum[] = $sourceId;
                    $report->pushed++;
                }
            } catch (\Throwable $e) {
                $logger->error('Failed to push asset: ' . $e->getMessage(), ['local_asset_id' => $localId]);
                $report->pushedFailed++;
            }

            if (($i + 1) % 5 === 0) {
                $this->persistRunCounts($run, $report);
            }
        }

        if (! empty($sourceIdsToAddToAlbum)) {
            $logger->info('Adding ' . count($sourceIdsToAddToAlbum) . ' asset(s) to source album');
            foreach (array_chunk(array_values(array_unique($sourceIdsToAddToAlbum)), 200) as $chunk) {
                $source->addAssetsToSourceAlbum($chunk);
            }
        }
    }

    private function downloadFromTargetAndUploadToSource(Album $album, ImmichClient $target, WritableSourceBackend $source, array $asset): ?string
    {
        $localId = $asset['id'];
        $tempPath = tempnam(sys_get_temp_dir(), 'immich-album-sync-push-');

        if ($tempPath === false) {
            throw new \RuntimeException('Cannot allocate temp file for push');
        }

        try {
            $target->downloadOriginalToFile($localId, $tempPath);

            $checksum = $asset['checksum'] ?? base64_encode(hash_file('sha1', $tempPath, binary: true));
            $createdAt = $asset['fileCreatedAt'] ?? now()->toIso8601String();
            $modifiedAt = $asset['fileModifiedAt'] ?? $createdAt;

            $response = $source->uploadAsset(
                filePath: $tempPath,
                filename: $asset['originalFileName'] ?? ($localId . '.bin'),
                checksumSha1: $checksum,
                fileCreatedAt: $createdAt,
                fileModifiedAt: $modifiedAt,
                deviceAssetId: 'immich-album-sync-push-' . $album->id . '-' . $localId,
                deviceId: 'immich-album-sync',
            );

            return $response['id'] ?? null;
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
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
        $trashedOnTarget = [];
        if (! empty($bulkPayload)) {
            $check = $target->bulkUploadCheck($bulkPayload);
            foreach (($check['results'] ?? []) as $result) {
                if (($result['action'] ?? null) === 'reject' && ($result['reason'] ?? null) === 'duplicate') {
                    $assetId = $result['assetId'] ?? null;
                    if ($assetId === null) {
                        continue;
                    }
                    if (($result['isTrashed'] ?? false) === true) {
                        $trashedOnTarget[$result['id']] = $assetId;
                    } else {
                        $alreadyOnTarget[$result['id']] = $assetId;
                    }
                }
            }

            if (! empty($trashedOnTarget)) {
                $logger->info(count($trashedOnTarget) . ' asset(s) exist on target but are trashed — attempting restore');
                $restored = $target->restoreAssetsFromTrash(array_values($trashedOnTarget));
                if ($restored) {
                    foreach ($trashedOnTarget as $remoteId => $assetId) {
                        $alreadyOnTarget[$remoteId] = $assetId;
                    }
                    $logger->info('Restored ' . count($trashedOnTarget) . ' trashed asset(s); linking them instead of re-uploading');
                } else {
                    $logger->info('No permission to restore from trash (asset.delete scope missing); will re-upload');
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
                    $this->upsertMapping($album->id, $asset->remoteId, $asset->checksum, $localId);
                    $localIds[] = $localId;
                    $report->deduped++;

                    continue;
                }

                $logger->info('Uploading: ' . $asset->filename, ['remote_id' => $asset->remoteId]);
                $localId = $this->downloadAndUpload($album, $source, $target, $asset);

                if ($localId !== null) {
                    $this->upsertMapping($album->id, $asset->remoteId, $asset->checksum, $localId);
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
                deviceAssetId: 'immich-album-sync-' . $album->id . '-' . $asset->remoteId,
                deviceId: 'immich-album-sync',
            );

            return $response['id'] ?? null;
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private function upsertMapping(int $albumId, string $remoteId, ?string $remoteChecksum, string $localAssetId): void
    {
        DB::transaction(function () use ($albumId, $remoteId, $remoteChecksum, $localAssetId): void {
            Mapping::query()->updateOrInsert(
                ['album_id' => $albumId, 'remote_id' => $remoteId],
                [
                    'remote_checksum' => $remoteChecksum,
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
            'pushed_count' => $report->pushed,
            'pushed_deduped_count' => $report->pushedDeduped,
            'pushed_failed_count' => $report->pushedFailed,
        ]);
    }
}
